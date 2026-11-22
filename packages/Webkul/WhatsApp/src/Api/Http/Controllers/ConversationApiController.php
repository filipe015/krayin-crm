<?php

namespace Webkul\WhatsApp\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Lead\Models\Lead;
use Webkul\WhatsApp\Channels\Cloud\CloudApiException;
use Webkul\WhatsApp\Http\Controllers\CloudTemplateController;
use Webkul\WhatsApp\Jobs\SendWhatsAppMessage;
use Webkul\WhatsApp\Models\Conversation;
use Webkul\WhatsApp\Models\Message;
use Webkul\WhatsApp\Models\QuickReply;
use Webkul\WhatsApp\Models\Session;
use Webkul\WhatsApp\Models\TemplateVariableMap;
use Webkul\WhatsApp\Support\ConversationWindow;
use Webkul\WhatsApp\Support\TemplateVariables;

/**
 * Conversations of the official WhatsApp numbers for automations (n8n): which ones need a
 * follow-up, their messages, and sending a free-form text (24-hour window open) or an
 * approved template. Everything sent goes through the Inbox, so the team sees it there.
 *
 * Automatic follow-ups are limited here, not in the automation: at most MAX_FOLLOWUPS per
 * conversation, GAP_HOURS apart, and never once the lead has an appointment.
 */
class ConversationApiController extends ApiController
{
    public const MAX_FOLLOWUPS = 2;

    public const GAP_HOURS = 24;

    public const FOLLOWUP_SOURCE = 'followup';

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'stage_ids'                => ['nullable', 'string', 'max:200'],
            'idle_hours'               => ['nullable', 'integer', 'min:0', 'max:2160'],
            'last_direction'           => ['nullable', 'in:inbound,outbound'],
            'window'                   => ['nullable', 'in:open,closed'],
            'followup_allowed'         => ['nullable', 'boolean'],
            'per_page'                 => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $stageIds = array_values(array_filter(array_map('intval', explode(',', (string) ($filters['stage_ids'] ?? '')))));

        $query = Conversation::query()
            ->whereNotNull('lead_id')
            ->where('status', '!=', 'closed')
            ->whereIn('session_id', Session::query()->where('channel', 'cloud')->select('id'))
            ->when($stageIds !== [], fn ($q) => $q->whereIn('lead_id', DB::table('leads')->whereIn('lead_pipeline_stage_id', $stageIds)->select('id')))
            ->when(isset($filters['idle_hours']), fn ($q) => $q->where('last_message_at', '<=', now()->subHours((int) $filters['idle_hours'])))
            ->when(($filters['window'] ?? null) === 'open', fn ($q) => $q->where('last_inbound_at', '>', now()->subHours(ConversationWindow::HOURS)))
            ->when(($filters['window'] ?? null) === 'closed', fn ($q) => $q->where(fn ($inner) => $inner->whereNull('last_inbound_at')->orWhere('last_inbound_at', '<=', now()->subHours(ConversationWindow::HOURS))))
            ->orderBy('last_message_at');

        $page = $query->paginate((int) ($filters['per_page'] ?? 50));

        $items = collect($page->items())->map(fn (Conversation $conversation) => $this->present($conversation));

        // Filters that need the computed fields (last message direction, follow-up rules).
        if (isset($filters['last_direction'])) {
            $items = $items->where('last_direction', $filters['last_direction']);
        }

        if (isset($filters['followup_allowed'])) {
            $items = $items->filter(fn ($item) => $item['followup']['allowed'] === (bool) $filters['followup_allowed']);
        }

        return $this->ok($items->values(), [
            'page'      => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total'     => $page->total(),
        ]);
    }

    public function messages(Request $request, int $id): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 30), 1), 100);
        $conversation = Conversation::query()->findOrFail($id);

        $rows = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('status', '!=', 'scheduled')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        return $this->ok($rows->map(fn (Message $message) => [
            'id'         => $message->id,
            'direction'  => $message->direction,
            'type'       => $message->message_type,
            'content'    => $message->content,
            'status'     => $message->status,
            'followup'   => $message->source === self::FOLLOWUP_SOURCE,
            'sender'     => $message->sender_name,
            'created_at' => $message->created_at?->toIso8601String(),
        ]));
    }

    public function quickReplies(): JsonResponse
    {
        return $this->ok(QuickReply::query()->orderBy('shortcut')->get(['id', 'shortcut', 'title', 'body']));
    }

    /**
     * Approved templates the automation can send, with the meaning saved for each variable.
     */
    public function templates(Request $request): JsonResponse
    {
        $session = Session::query()
            ->where('channel', 'cloud')
            ->when($request->query('session_id'), fn ($q, $v) => $q->whereKey((int) $v))
            ->orderBy('id')
            ->first();

        if ($session === null) {
            return $this->fail('no_number', 'Nenhum número oficial conectado.', 404);
        }

        try {
            $templates = collect(CloudTemplateController::approved($session));
        } catch (CloudApiException $exception) {
            return $this->fail('meta_error', $exception->getMessage(), 502);
        }

        return $this->ok($templates->where('supported', true)->map(fn ($template) => [
            'name'      => $template['name'],
            'language'  => $template['language'],
            'category'  => $template['category'],
            'body'      => $template['body'],
            'variables' => $template['variables'],
            'sources'   => TemplateVariableMap::sourcesFor($template['name'], $template['language']),
        ])->values());
    }

    public function sendText(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'text'     => ['required', 'string', 'max:4096'],
            'followup' => ['nullable', 'boolean'],
        ]);

        $conversation = $this->conversation($id);

        if ($conversation instanceof JsonResponse) {
            return $conversation;
        }

        if (! ConversationWindow::isOpen($conversation->last_inbound_at)) {
            return $this->fail('window_closed', 'A janela de 24 h está fechada: só é possível enviar um modelo aprovado.', 409);
        }

        if ($blocked = $this->followupBlock($conversation, (bool) ($data['followup'] ?? false))) {
            return $blocked;
        }

        $message = $this->queue($conversation, trim($data['text']), null, (bool) ($data['followup'] ?? false));

        return $this->ok($this->summary($message), [], 201);
    }

    public function sendTemplate(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:512'],
            'language'    => ['required', 'string', 'max:20'],
            'variables'   => ['nullable', 'array', 'max:20'],
            'variables.*' => ['nullable', 'string', 'max:1024'],
            'followup'    => ['nullable', 'boolean'],
        ]);

        $conversation = $this->conversation($id);

        if ($conversation instanceof JsonResponse) {
            return $conversation;
        }

        if ($blocked = $this->followupBlock($conversation, (bool) ($data['followup'] ?? false))) {
            return $blocked;
        }

        try {
            $template = collect(CloudTemplateController::approved($conversation->session))
                ->first(fn ($item) => $item['name'] === $data['name'] && $item['language'] === $data['language']);
        } catch (CloudApiException $exception) {
            return $this->fail('meta_error', $exception->getMessage(), 502);
        }

        if ($template === null || ! $template['supported']) {
            return $this->fail('template_unavailable', 'Modelo inexistente, não aprovado ou não suportado.', 422);
        }

        // Without explicit values, the variables follow the meaning saved with the template.
        $values = array_values(array_map(fn ($value) => trim((string) $value), (array) ($data['variables'] ?? [])));

        if ($values === [] && $template['variables'] > 0) {
            $sources = TemplateVariableMap::sourcesFor($template['name'], $template['language']);

            if (count($sources) !== $template['variables']) {
                return $this->fail('variables_required', 'Informe as variáveis: o modelo não tem o significado delas salvo no CRM.', 422);
            }

            $lead = Lead::query()->with(['person:id,name', 'user:id,name'])->find($conversation->lead_id);
            $appointment = $lead !== null ? TemplateVariables::nextAppointment($lead) : null;
            $values = array_map(fn ($source) => TemplateVariables::resolve($source, $lead, $appointment, $conversation), $sources);
        }

        if (count($values) !== $template['variables'] || in_array('', $values, true)) {
            return $this->fail('variables_invalid', 'O modelo precisa de '.$template['variables'].' variável(is), todas preenchidas.', 422);
        }

        $payload = [
            'name'       => $template['name'],
            'language'   => $template['language'],
            'components' => $values === [] ? [] : [[
                'type'       => 'body',
                'parameters' => array_map(fn ($value) => ['type' => 'text', 'text' => $value], $values),
            ]],
        ];

        $message = $this->queue($conversation, CloudTemplateController::render($template['body'], $values), $payload, (bool) ($data['followup'] ?? false));

        return $this->ok($this->summary($message), [], 201);
    }

    private function conversation(int $id): Conversation|JsonResponse
    {
        $conversation = Conversation::query()->with('session')->findOrFail($id);

        if ($conversation->session?->channel !== 'cloud') {
            return $this->fail('not_cloud', 'Só conversas do número oficial (API da Meta) são aceitas.', 422);
        }

        if ($conversation->session->status !== 'WORKING') {
            return $this->fail('session_offline', 'O número está desconectado.', 409);
        }

        if (blank($conversation->contact_phone)) {
            return $this->fail('no_phone', 'A conversa não tem telefone.', 422);
        }

        return $conversation;
    }

    /**
     * @param  array<string, mixed>|null  $templatePayload
     */
    private function queue(Conversation $conversation, string $content, ?array $templatePayload, bool $followup): Message
    {
        $message = DB::transaction(function () use ($conversation, $content, $templatePayload, $followup) {
            $message = Message::query()->create([
                'conversation_id'  => $conversation->id,
                'direction'        => 'outbound',
                'status'           => 'queued',
                'source'           => $followup ? self::FOLLOWUP_SOURCE : 'api',
                'content'          => $content,
                'message_type'     => $templatePayload !== null ? 'template' : 'text',
                'template_payload' => $templatePayload,
                'sender_name'      => trans('whatsapp::app.inbox.automation_sender'),
            ]);

            $conversation->last_message_at = $message->created_at;
            $conversation->last_message_preview = Str::limit($content, 280, '');
            $conversation->save();

            return $message;
        });

        SendWhatsAppMessage::dispatch($message->id);

        return $message;
    }

    /**
     * The follow-up rules. They only apply to what is sent as a follow-up.
     */
    private function followupBlock(Conversation $conversation, bool $followup): ?JsonResponse
    {
        if (! $followup) {
            return null;
        }

        $reason = $this->followupReason($conversation);

        return $reason === null ? null : $this->fail($reason, match ($reason) {
            'followup_limit'  => 'Esta conversa já recebeu o máximo de '.self::MAX_FOLLOWUPS.' follow-ups.',
            'followup_gap'    => 'O último follow-up foi há menos de '.self::GAP_HOURS.' horas.',
            'has_appointment' => 'O lead já tem agendamento: follow-up automático não se aplica.',
            default           => 'Follow-up não permitido.',
        }, 409);
    }

    private function followupReason(Conversation $conversation): ?string
    {
        $sent = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('source', self::FOLLOWUP_SOURCE)
            ->where('direction', 'outbound')
            ->where('status', '!=', 'failed');

        if ((clone $sent)->count() >= self::MAX_FOLLOWUPS) {
            return 'followup_limit';
        }

        $last = (clone $sent)->max('created_at');

        if ($last !== null && now()->diffInHours($last, true) < self::GAP_HOURS) {
            return 'followup_gap';
        }

        $lead = $conversation->lead_id ? Lead::query()->find($conversation->lead_id) : null;

        if ($lead !== null && TemplateVariables::nextAppointment($lead) !== null) {
            return 'has_appointment';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Conversation $conversation): array
    {
        $lead = Lead::query()->with(['person:id,name', 'stage:id,name', 'user:id,name'])->find($conversation->lead_id);

        $last = Message::query()->where('conversation_id', $conversation->id)->where('status', '!=', 'scheduled')->orderByDesc('id')->first();

        $followups = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('source', self::FOLLOWUP_SOURCE)
            ->where('direction', 'outbound')
            ->where('status', '!=', 'failed');

        $reason = $this->followupReason($conversation);

        return [
            'id'              => $conversation->id,
            'session_id'      => $conversation->session_id,
            'contact_name'    => $conversation->contact_name,
            'contact_phone'   => $conversation->contact_phone,
            'lead'            => $lead === null ? null : [
                'id'     => $lead->id,
                'title'  => $lead->title,
                'person' => $lead->person?->name,
                'stage'  => $lead->stage ? ['id' => $lead->stage->id, 'name' => $lead->stage->name] : null,
                'owner'  => $lead->user?->name,
            ],
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'last_inbound_at' => $conversation->last_inbound_at?->toIso8601String(),
            'last_direction'  => $last?->direction,
            'window'          => [
                'open'       => ConversationWindow::isOpen($conversation->last_inbound_at),
                'expires_at' => ConversationWindow::expiresAt($conversation)?->toIso8601String(),
            ],
            'followup'        => [
                'sent'    => (clone $followups)->count(),
                'last_at' => ($at = (clone $followups)->max('created_at')) ? \Illuminate\Support\Carbon::parse($at)->toIso8601String() : null,
                'allowed' => $reason === null,
                'reason'  => $reason,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Message $message): array
    {
        return [
            'id'              => $message->id,
            'conversation_id' => $message->conversation_id,
            'type'            => $message->message_type,
            'status'          => $message->status,
            'followup'        => $message->source === self::FOLLOWUP_SOURCE,
            'content'         => $message->content,
        ];
    }
}
