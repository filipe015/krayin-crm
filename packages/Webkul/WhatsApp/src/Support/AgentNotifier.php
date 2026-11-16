<?php

namespace Webkul\WhatsApp\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Webkul\WhatsApp\Jobs\SendAgentNotification;
use Webkul\WhatsApp\Models\Conversation;
use Webkul\WhatsApp\Models\Message;

/**
 * WhatsApp notice to the team (WHATSAPP_NOTIFY_*): a new automatic lead, and new messages
 * received in conversations that already have a lead. Notices always go out through a WAHA
 * session, also for conversations that came through the Cloud API. Every send is a queued
 * job, and nothing here ever throws into the webhook.
 */
class AgentNotifier
{
    public const EVENTS = ['new_lead', 'new_message'];

    private const MEDIA_LABELS = [
        'image'    => '[imagem]',
        'audio'    => '[áudio]',
        'video'    => '[vídeo]',
        'document' => '[documento]',
        'sticker'  => '[figurinha]',
        'location' => '[localização]',
        'contact'  => '[contato]',
    ];

    public static function session(): string
    {
        return trim((string) config('whatsapp.notify.session', ''));
    }

    /**
     * @return list<string>  chatIds: digits become 55…@c.us, anything with "@" is kept as is.
     */
    public static function destinations(): array
    {
        $chats = [];

        foreach (explode(',', (string) config('whatsapp.notify.to', '')) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            if (str_contains($entry, '@')) {
                $chats[] = $entry;

                continue;
            }

            $digits = (string) preg_replace('/\D/', '', $entry);

            if ($digits === '') {
                continue;
            }

            // A Brazilian number without the country code (DDD + number).
            if (strlen($digits) <= 11 && ! str_starts_with($digits, '55')) {
                $digits = '55'.$digits;
            }

            $chats[] = $digits.'@c.us';
        }

        return array_values(array_unique($chats));
    }

    public static function enabled(): bool
    {
        return self::session() !== '' && self::destinations() !== [];
    }

    public static function wants(string $event): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $events = array_map(fn ($name) => strtolower(trim($name)), explode(',', (string) config('whatsapp.notify.events', '')));

        return in_array($event, $events, true);
    }

    /**
     * Events of the notice session itself are dropped, so it never becomes a conversation or
     * a lead. Only needed when WAHA_SESSIONS is empty (otherwise that list already decides).
     */
    public static function ignoresSession(string $session): bool
    {
        return self::enabled() && WahaSessions::allowed() === null && $session === self::session();
    }

    public function newLead(Conversation $conversation): void
    {
        $this->safely(fn () => $this->leadNotice($conversation));
    }

    public function newMessage(Conversation $conversation, Message $message): void
    {
        $this->safely(fn () => $this->messageNotice($conversation, $message));
    }

    private function safely(\Closure $notice): void
    {
        try {
            $notice();
        } catch (\Throwable $exception) {
            Log::warning('WhatsApp notice failed.', ['error' => $exception->getMessage()]);
        }
    }

    private function leadNotice(Conversation $conversation): void
    {
        if (! self::wants('new_lead')) {
            return;
        }

        $origin = filled($conversation->ad_title)
            ? (string) $conversation->ad_title
            : ($conversation->ad_captured_at !== null ? 'anúncio' : 'mensagem direta');

        $this->send(sprintf(
            'Novo lead no CRM: %s (%s) — origem: %s. Abrir: %s',
            self::name($conversation),
            self::phone($conversation),
            Str::limit($origin, 120),
            self::url($conversation),
        ));
    }

    private function messageNotice(Conversation $conversation, Message $message): void
    {
        if (! self::wants('new_message') || $message->direction !== 'inbound') {
            return;
        }

        $minutes = (int) config('whatsapp.notify.cooldown_minutes', 30);

        try {
            if ($minutes > 0 && ! Cache::add('whatsapp.notify.message.'.$conversation->id, true, $minutes * 60)) {
                return;
            }
        } catch (\Throwable $exception) {
            Log::warning('WhatsApp notice skipped: the cooldown could not be checked.', ['error' => $exception->getMessage()]);

            return;
        }

        $this->send(sprintf(
            'Nova mensagem de %s (%s): «%s». Responder: %s',
            self::name($conversation),
            self::phone($conversation),
            self::excerpt($message),
            self::url($conversation),
        ));
    }

    public static function excerpt(Message $message): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $message->content));
        $label = self::MEDIA_LABELS[$message->message_type] ?? null;

        if ($text === '') {
            return $label ?? '[mensagem]';
        }

        return ($label !== null ? $label.' ' : '').Str::limit($text, 120, '…');
    }

    private static function name(Conversation $conversation): string
    {
        $name = trim((string) $conversation->contact_name);

        return $name !== '' ? $name : 'Contato sem nome';
    }

    private static function phone(Conversation $conversation): string
    {
        $phone = ConversationLead::phone($conversation);

        return $phone !== '' ? '+'.$phone : 'sem número';
    }

    /**
     * Inbox already open on the conversation, always on APP_URL (the webhook may arrive
     * through another host name).
     */
    private static function url(Conversation $conversation): string
    {
        return rtrim((string) config('app.url'), '/').route('admin.whatsapp.inbox.index', ['c' => $conversation->id], false);
    }

    private function send(string $text): void
    {
        $session = self::session();

        foreach (self::destinations() as $chatId) {
            try {
                SendAgentNotification::dispatch($session, $chatId, $text);
            } catch (\Throwable $exception) {
                Log::warning('WhatsApp notice could not be queued.', ['chat_id' => $chatId, 'error' => $exception->getMessage()]);
            }
        }
    }
}
