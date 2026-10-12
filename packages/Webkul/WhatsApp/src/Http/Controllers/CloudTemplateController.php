<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\WhatsApp\Channels\Cloud\CloudApiException;
use Webkul\WhatsApp\Channels\Cloud\CloudClient;
use Webkul\WhatsApp\Jobs\SendWhatsAppMessage;
use Webkul\WhatsApp\Models\Conversation;
use Webkul\WhatsApp\Models\Message;
use Webkul\WhatsApp\Models\Session;
use Webkul\WhatsApp\Support\InboxPresenter;
use Webkul\WhatsApp\Support\ScheduleTime;

/**
 * Approved message templates of an official WhatsApp number: the only way to write to a
 * customer outside the 24-hour window.
 */
class CloudTemplateController extends Controller
{
    private const CACHE_SECONDS = 300;

    public function index(int $id): JsonResponse
    {
        $conversation = $this->conversation($id);

        try {
            return response()->json(['data' => $this->usable($conversation->session)]);
        } catch (CloudApiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:512'],
            'language'    => ['required', 'string', 'max:20'],
            'variables'   => ['nullable', 'array', 'max:20'],
            'variables.*' => ['nullable', 'string', 'max:1024'],
            'scheduled_at'   => ['nullable', 'date_format:Y-m-d\TH:i'],
            'schedule_title' => ['nullable', 'string', 'max:100'],
        ]);

        $scheduledAt = ScheduleTime::parse($data['scheduled_at'] ?? null);
        $title = trim((string) ($data['schedule_title'] ?? '')) ?: null;

        $conversation = $this->conversation($id);

        abort_if($conversation->session->status !== 'WORKING', 409, trans('whatsapp::app.inbox.session_offline'));
        abort_if(blank($conversation->contact_phone), 422, trans('whatsapp::app.inbox.no_identity'));

        try {
            $template = collect($this->usable($conversation->session))
                ->first(fn ($item) => $item['name'] === $data['name'] && $item['language'] === $data['language']);
        } catch (CloudApiException $exception) {
            abort(502, $exception->getMessage());
        }

        abort_if($template === null || ! $template['supported'], 422, trans('whatsapp::app.inbox.template_unavailable'));

        $values = array_values(array_map(fn ($value) => trim((string) $value), (array) ($data['variables'] ?? [])));

        abort_if(count($values) !== $template['variables'] || in_array('', $values, true), 422, trans('whatsapp::app.inbox.template_variables'));

        $components = $values === [] ? [] : [[
            'type'       => 'body',
            'parameters' => array_map(fn ($value) => ['type' => 'text', 'text' => $value], $values),
        ]];

        $rendered = self::render($template['body'], $values);
        $user = auth()->guard('user')->user();

        $message = DB::transaction(function () use ($conversation, $template, $components, $rendered, $user, $scheduledAt, $title) {
            $message = Message::query()->create([
                'conversation_id'  => $conversation->id,
                'direction'        => 'outbound',
                'status'           => $scheduledAt !== null ? 'scheduled' : 'queued',
                'scheduled_at'     => $scheduledAt,
                'scheduled_title'  => $scheduledAt !== null ? $title : null,
                'source'           => 'crm',
                'content'          => $rendered,
                'message_type'     => 'template',
                'template_payload' => ['name' => $template['name'], 'language' => $template['language'], 'components' => $components],
                'sent_by_user_id'  => $user->id,
                'sender_name'      => $user->name,
            ]);

            // A scheduled template changes nothing in the conversation until it is sent.
            if ($scheduledAt === null) {
                $conversation->last_message_at = $message->created_at;
                $conversation->last_message_preview = Str::limit($rendered, 280, '');
                $conversation->status = 'open';
            }

            $conversation->assigned_user_id ??= $user->id;
            $conversation->save();

            return $message;
        });

        if ($scheduledAt === null) {
            SendWhatsAppMessage::dispatch($message->id);
        }

        return response()->json(['data' => InboxPresenter::message($message->refresh())], 201);
    }

    private function conversation(int $id): Conversation
    {
        $conversation = Conversation::query()->whereNotNull('session_id')->with('session')->findOrFail($id);

        abort_unless($conversation->session?->channel === 'cloud', 404);

        return $conversation;
    }

    /**
     * Approved templates, with what the Inbox needs to fill and send them.
     *
     * @return list<array{name: string, language: string, category: string, body: string, header: ?string, footer: ?string, variables: int, supported: bool}>
     *
     * @throws CloudApiException
     */
    private function usable(Session $session): array
    {
        $raw = Cache::remember('whatsapp.cloud.templates.'.$session->id, self::CACHE_SECONDS, fn () => CloudClient::forSession($session)->templates());

        return collect($raw)
            ->filter(fn ($template) => strtoupper((string) ($template['status'] ?? '')) === 'APPROVED')
            ->map(fn ($template) => self::describe((array) $template))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array{name: string, language: string, category: string, body: string, header: ?string, footer: ?string, variables: int, supported: bool}
     */
    public static function describe(array $template): array
    {
        $body = '';
        $header = null;
        $footer = null;
        $supported = true;

        foreach ((array) ($template['components'] ?? []) as $component) {
            $type = strtoupper((string) ($component['type'] ?? ''));

            if ($type === 'BODY') {
                $body = (string) ($component['text'] ?? '');
            } elseif ($type === 'FOOTER') {
                $footer = (string) ($component['text'] ?? '');
            } elseif ($type === 'HEADER') {
                $header = (string) ($component['text'] ?? '');

                // Media headers and headers with variables need parameters this version does not collect.
                if (strtoupper((string) ($component['format'] ?? 'TEXT')) !== 'TEXT' || str_contains($header, '{{')) {
                    $supported = false;
                }
            } elseif ($type === 'BUTTONS') {
                foreach ((array) ($component['buttons'] ?? []) as $button) {
                    if (str_contains((string) ($button['url'] ?? ''), '{{') || strtoupper((string) ($button['type'] ?? '')) === 'COPY_CODE') {
                        $supported = false;
                    }
                }
            }
        }

        preg_match_all('/\{\{(\d+)\}\}/', $body, $matches);

        return [
            'name'      => (string) ($template['name'] ?? ''),
            'language'  => (string) ($template['language'] ?? ''),
            'category'  => strtoupper((string) ($template['category'] ?? '')),
            'body'      => $body,
            'header'    => $header,
            'footer'    => $footer,
            'variables' => $matches[1] === [] ? 0 : (int) max($matches[1]),
            'supported' => $supported && $body !== '',
        ];
    }

    /**
     * @param  list<string>  $values  The values for {{1}}, {{2}}, ...
     */
    public static function render(string $body, array $values): string
    {
        return (string) preg_replace_callback('/\{\{(\d+)\}\}/', fn ($match) => $values[(int) $match[1] - 1] ?? $match[0], $body);
    }
}
