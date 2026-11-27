<?php

namespace Webkul\WhatsApp\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            'stage_ids' => ['nullable', 'string', 'max:200'],
            'idle_hours' => ['nullable', 'integer', 'min:0', 'max:2160'],
            'last_direction' => ['nullable', 'in:inbound,outbound'],
            'window' => ['nullable', 'in:open,closed'],
            'followup_allowed' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'channel' => ['nullable', 'in:cloud,waha,all'],
            'include_unlinked' => ['nullable', 'boolean'],
        ]);

        $stageIds = array_values(array_filter(array_map('intval', explode(',', (string) ($filters['stage_ids'] ?? '')))));

        // "cloud" stays the default so existing automations keep seeing only the official number.
        $channel = $filters['channel'] ?? 'cloud';
        $window = $filters['window'] ?? null;
        $cutoff = now()->subHours(ConversationWindow::HOURS);

        $cloudIds = Session::query()->where('channel', 'cloud')->select('id');
        $wahaIds = Session::query()->where('channel', 'waha')->select('id');

        $query = Conversation::query()
            ->with('session:id,channel,status')
            ->when(! ($filters['include_unlinked'] ?? false), fn ($q) => $q->whereNotNull('lead_id'))
            ->where('status', '!=', 'closed')
            // Groups (like the VIP group) and broadcasts are never follow-up targets.
            ->where(fn ($q) => $q->whereNull('chat_id')->orWhere(fn ($inner) => $inner->where('chat_id', 'not like', '%@g.us')->where('chat_id', 'not like', '%@broadcast')->where('chat_id', 'not like', '%@newsletter')))
            ->whereIn('session_id', match ($channel) {
                'waha' => $wahaIds,
                'all' => Session::query()->whereIn('channel', ['cloud', 'waha'])->select('id'),
                default => $cloudIds,
            })
            ->when($stageIds !== [], fn ($q) => $q->whereIn('lead_id', DB::table('leads')->whereIn('lead_pipeline_stage_id', $stageIds)->select('id')))
            ->when(isset($filters['idle_hours']), fn ($q) => $q->where('last_message_at', '<=', now()->subHours((int) $filters['idle_hours'])))
            // The 24h window only exists on the official channel: WAHA conversations are always "open".
            ->when($window === 'open', fn ($q) => $q->where(fn ($inner) => $inner->whereIn('session_id', $wahaIds)->orWhere('last_inbound_at', '>', $cutoff)))
            ->when($window === 'closed', fn ($q) => $q->whereIn('session_id', $cloudIds)->where(fn ($inner) => $inner->whereNull('last_inbound_at')->orWhere('last_inbound_at', '<=', $cutoff)))
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
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
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
            'id' => $message->id,
            'direction' => $message->direction,
            'type' => $message->message_type,
            'content' => $message->content,
            'status' => $message->status,
            'followup' => $message->source === self::FOLLOWUP_SOURCE,
            'sender' => $message->sender_name,
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
            'name' => $template['name'],
            'language' => $template['language'],
            'category' => $template['category'],
            'body' => $template['body'],
            'variables' => $template['variables'],
            'sources' => TemplateVariableMap::sourcesFor($template['name'], $template['language']),
        ])->values());
    }

    public function sendText(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:4096'],
            'followup' => ['nullable', 'boolean'],
        ]);

        $conversation = $this->conversation($id, allowWaha: true);

        if ($conversation instanceof JsonResponse) {
            return $conversation;
        }

        if (! ConversationWindow::isOpenFor($conversation)) {
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
            'name' => ['required', 'string', 'max:512'],
            'language' => ['required', 'string', 'max:20'],
            'variables' => ['nullable', 'array', 'max:20'],
            'variables.*' => ['nullable', 'string', 'max:1024'],
            'followup' => ['nullable', 'boolean'],
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
            'name' => $template['name'],
            'language' => $template['language'],
            'components' => $values === [] ? [] : [[
                'type' => 'body',
                'parameters' => array_map(fn ($value) => ['type' => 'text', 'text' => $value], $values),
            ]],
        ];

        $message = $this->queue($conversation, CloudTemplateController::render($template['body'], $values), $payload, (bool) ($data['followup'] ?? false));

        return $this->ok($this->summary($message), [], 201);
    }

    /**
     * Free-form text also goes through WAHA; templates exist only on the official channel.
     */
    private function conversation(int $id, bool $allowWaha = false): Conversation|JsonResponse
    {
        $conversation = Conversation::query()->with('session')->findOrFail($id);
        $channel = $conversation->session?->channel;

        if ($channel !== 'cloud' && ! ($allowWaha && $channel === 'waha')) {
            return $this->fail('not_cloud', $allowWaha
                ? 'Só conversas do número oficial (API da Meta) ou do WhatsApp via WAHA são aceitas.'
                : 'Só conversas do número oficial (API da Meta) aceitam modelos.', 422);
        }

        // Never message a group (e.g. the VIP group) or a broadcast list automatically.
        if (preg_match('/@(g\.us|broadcast|newsletter)$/', (string) $conversation->chat_id)) {
            return $this->fail('not_direct_chat', 'Só conversas individuais são aceitas.', 422);
        }

        if ($conversation->session->status !== 'WORKING') {
            return $this->fail('session_offline', 'O número está desconectado.', 409);
        }

        // WAHA can also reach a contact by its LID, so the phone is only mandatory on the Cloud API.
        if (blank($conversation->contact_phone) && ! ($channel === 'waha' && filled($conversation->contact_lid))) {
            return $this->fail('no_phone', 'A conversa não tem telefone.', 422);
        }

        return $conversation;
    }

    /**
     * @return array{max: int, gap_hours: int, daily_cap: int}
     */
    private function limits(Conversation $conversation): array
    {
        $channel = $conversation->session?->channel === 'waha' ? 'waha' : 'cloud';

        return [
            'max' => (int) config("whatsapp.api_followup.{$channel}.max", self::MAX_FOLLOWUPS),
            'gap_hours' => (int) config("whatsapp.api_followup.{$channel}.gap_hours", self::GAP_HOURS),
            'daily_cap' => $channel === 'waha' ? (int) config('whatsapp.api_followup.waha.daily_cap', 0) : 0,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $templatePayload
     */
    private function queue(Conversation $conversation, string $content, ?array $templatePayload, bool $followup): Message
    {
        $message = DB::transaction(function () use ($conversation, $content, $templatePayload, $followup) {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'status' => 'queued',
                'source' => $followup ? self::FOLLOWUP_SOURCE : 'api',
                'content' => $content,
                'message_type' => $templatePayload !== null ? 'template' : 'text',
                'template_payload' => $templatePayload,
                'sender_name' => trans('whatsapp::app.inbox.automation_sender'),
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

        $limits = $this->limits($conversation);

        return $reason === null ? null : $this->fail($reason, match ($reason) {
            'followup_limit' => 'Esta conversa já recebeu o máximo de '.$limits['max'].' follow-ups.',
            'followup_gap' => 'O último follow-up foi há menos de '.$limits['gap_hours'].' horas.',
            'daily_limit' => 'O limite diário de '.$limits['daily_cap'].' follow-ups deste número foi atingido.',
            'has_appointment' => 'O lead já tem agendamento: follow-up automático não se aplica.',
            default => 'Follow-up não permitido.',
        }, 409);
    }

    private function followupReason(Conversation $conversation): ?string
    {
        $limits = $this->limits($conversation);

        $sent = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('source', self::FOLLOWUP_SOURCE)
            ->where('direction', 'outbound')
            ->where('status', '!=', 'failed');

        if ((clone $sent)->count() >= $limits['max']) {
            return 'followup_limit';
        }

        $last = (clone $sent)->max('created_at');

        if ($last !== null && now()->diffInHours($last, true) < $limits['gap_hours']) {
            return 'followup_gap';
        }

        if ($limits['daily_cap'] > 0 && Message::query()
            ->whereIn('conversation_id', Conversation::query()->where('session_id', $conversation->session_id)->select('id'))
            ->where('source', self::FOLLOWUP_SOURCE)
            ->where('direction', 'outbound')
            ->where('status', '!=', 'failed')
            ->where('created_at', '>', now()->subDay())
            ->count() >= $limits['daily_cap']) {
            return 'daily_limit';
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
            'id' => $conversation->id,
            'session_id' => $conversation->session_id,
            'channel' => $conversation->session?->channel ?? 'cloud',
            'contact_name' => $conversation->contact_name,
            'contact_phone' => $conversation->contact_phone,
            'lead' => $lead === null ? null : [
                'id' => $lead->id,
                'title' => $lead->title,
                'person' => $lead->person?->name,
                'stage' => $lead->stage ? ['id' => $lead->stage->id, 'name' => $lead->stage->name] : null,
                'owner' => $lead->user?->name,
            ],
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'last_inbound_at' => $conversation->last_inbound_at?->toIso8601String(),
            'last_direction' => $last?->direction,
            'window' => [
                'open' => ConversationWindow::isOpenFor($conversation),
                'expires_at' => ConversationWindow::expiresAtFor($conversation)?->toIso8601String(),
            ],
            'followup' => [
                'sent' => (clone $followups)->count(),
                'last_at' => ($at = (clone $followups)->max('created_at')) ? Carbon::parse($at)->toIso8601String() : null,
                'allowed' => $reason === null,
                'reason' => $reason,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Message $message): array
    {
        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'type' => $message->message_type,
            'status' => $message->status,
            'followup' => $message->source === self::FOLLOWUP_SOURCE,
            'content' => $message->content,
        ];
    }
}
