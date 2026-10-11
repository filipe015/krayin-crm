<?php

namespace Webkul\WhatsApp\Channels\Waha;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\WhatsApp\Channels\Events\InboundMessage;
use Webkul\WhatsApp\Channels\Events\MessageAck;
use Webkul\WhatsApp\Channels\Events\MessageEdited;
use Webkul\WhatsApp\Channels\Events\MessageRevoked;
use Webkul\WhatsApp\Channels\Events\PresenceChanged;
use Webkul\WhatsApp\Channels\Events\SessionStatus;
use Webkul\WhatsApp\Models\Conversation;
use Webkul\WhatsApp\Models\Message;
use Webkul\WhatsApp\Models\Session;
use Webkul\WhatsApp\Support\LeadPhoneFinder;
use Webkul\WhatsApp\Support\PhoneNumberMatcher;

class WahaEventHandler
{
    private const STATUS_RANK = ['queued' => 0, 'sending' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4];

    /**
     * How long to wait before searching a lead again for a chat that has none.
     */
    private const LEAD_LOOKUP_COOLDOWN = 600;

    public function __construct(protected LeadPhoneFinder $leads) {}

    public function handle(InboundMessage|MessageAck|MessageEdited|MessageRevoked|PresenceChanged|SessionStatus $event): void
    {
        match (true) {
            $event instanceof InboundMessage => $this->message($event),
            $event instanceof MessageAck     => $this->ack($event),
            $event instanceof MessageEdited  => $this->edited($event),
            $event instanceof MessageRevoked => $this->revoked($event),
            $event instanceof PresenceChanged => $this->presence($event),
            $event instanceof SessionStatus  => $this->sessionStatus($event),
        };
    }

    /**
     * "typing" and "recording" are kept for a few seconds: WAHA only says when they start
     * and stop, and a lost "paused" must not leave the indicator on forever.
     */
    private function presence(PresenceChanged $event): void
    {
        $session = Session::query()->where('channel', 'waha')->where('external_id', $event->session)->first();

        if ($session === null) {
            return;
        }

        $phone = str_ends_with($event->chatId, '@c.us') ? strstr($event->chatId, '@', true) : null;

        $conversation = Conversation::query()
            ->where('session_id', $session->id)
            ->where(function ($query) use ($event, $phone) {
                $query->where('chat_id', $event->chatId)->orWhere('contact_lid', $event->chatId);

                if ($phone !== null && $phone !== '') {
                    $query->orWhereIn('contact_phone', PhoneNumberMatcher::variants($phone));
                }
            })
            ->first(['id']);

        if ($conversation === null) {
            return;
        }

        $key = 'whatsapp.presence.'.$conversation->id;

        if (in_array($event->state, ['typing', 'recording'], true)) {
            Cache::put($key, $event->state, 12);
        } else {
            Cache::forget($key);
        }
    }

    private function findMessage(string $session, string $externalId): ?Message
    {
        return Message::query()
            ->where('external_message_id', $externalId)
            ->whereHas('conversation.session', fn ($query) => $query
                ->where('channel', 'waha')
                ->where('external_id', $session))
            ->first();
    }

    private function edited(MessageEdited $event): void
    {
        $message = $this->findMessage($event->session, $event->externalId);

        if ($message === null || $message->revoked_at !== null) {
            return;
        }

        $message->content = $event->body;
        $message->edited_at = now();
        $message->save();

        $conversation = $message->conversation;

        if ((int) Message::query()->where('conversation_id', $conversation->id)->max('id') === $message->id) {
            $conversation->last_message_preview = Str::limit($event->body, 280, '');
            $conversation->save();
        }
    }

    /**
     * The content is kept, so the audit trail survives; the Inbox shows it as deleted.
     */
    private function revoked(MessageRevoked $event): void
    {
        $message = $this->findMessage($event->session, $event->externalId);

        if ($message !== null && $message->revoked_at === null) {
            $message->revoked_at = now();
            $message->save();
        }
    }

    private function session(string $name): Session
    {
        return Session::query()->firstOrCreate(
            ['channel' => 'waha', 'external_id' => $name],
            ['name' => $name],
        );
    }

    private function sessionStatus(SessionStatus $event): void
    {
        $session = $this->session($event->session);

        $session->status = $event->status;
        $session->status_changed_at = now();
        $session->phone = $event->phone ?? $session->phone;
        $session->save();
    }

    /**
     * WAHA names the same contact by LID in one event and by phone in another (for
     * instance a message sent through the API), so a chat is matched by any identity
     * we know before a new conversation is opened.
     */
    private function conversation(Session $session, InboundMessage $event): Conversation
    {
        $phones = $event->phone !== null ? PhoneNumberMatcher::variants($event->phone) : [];

        $existing = Conversation::query()
            ->where('session_id', $session->id)
            ->where(function ($query) use ($event, $phones) {
                $query->where('chat_id', $event->chatId);

                if ($event->lid !== null) {
                    $query->orWhere('contact_lid', $event->lid);
                }

                if ($phones !== []) {
                    $query->orWhereIn('contact_phone', $phones);
                }
            })
            ->orderByRaw('chat_id = ? desc', [$event->chatId])
            ->orderBy('id')
            ->first();

        return $existing ?? Conversation::query()->createOrFirst(
            ['session_id' => $session->id, 'chat_id' => $event->chatId],
            ['canal_origem' => 'waha'],
        );
    }

    private function message(InboundMessage $event): void
    {
        $session = $this->session($event->session);
        $sentAt = Carbon::instance($event->sentAt)->setTimezone(config('app.timezone'));

        $conversation = $this->conversation($session, $event);

        $conversation->contact_phone ??= $event->phone;
        $conversation->contact_lid ??= $event->lid;
        $conversation->contact_name = $event->contactName ?? $conversation->contact_name;

        if ($conversation->lead_id === null
            && $conversation->contact_phone !== null
            && Cache::add('whatsapp.lead-lookup.'.$conversation->id, true, self::LEAD_LOOKUP_COOLDOWN)) {
            $conversation->lead_id = $this->leads->find($conversation->contact_phone);
        }

        $message = ($event->fromMe ? $this->adoptPendingSend($conversation, $event) : null) ?? Message::query()->createOrFirst(
            ['conversation_id' => $conversation->id, 'external_message_id' => $event->externalId],
            [
                'direction'      => $event->fromMe ? 'outbound' : 'inbound',
                'status'         => $event->fromMe ? 'sent' : 'received',
                'source'         => $event->source,
                'sender_name'    => $event->fromMe ? null : $event->contactName,
                'content'        => $event->body,
                'message_type'   => $event->type,
                'media_path'     => $event->mediaPath,
                'media_mime'     => $event->mediaMime,
                'media_filename' => $event->mediaFilename,
                'created_at'     => $sentAt,
                'updated_at'     => $sentAt,
            ],
        );

        if ($message->wasRecentlyCreated) {
            if ($conversation->last_message_at === null || $sentAt->gt($conversation->last_message_at)) {
                $conversation->last_message_at = $sentAt;
                $conversation->last_message_preview = Str::limit($event->body ?? '['.$event->type.']', 280, '');
            }

            if (! $event->fromMe && ($conversation->last_inbound_at === null || $sentAt->gt($conversation->last_inbound_at))) {
                $conversation->last_inbound_at = $sentAt;
            }
        }

        $conversation->save();
    }

    /**
     * WAHA echoes a message the CRM sent (source "api") back through the webhook, often
     * before the send request has even returned. Recognise it by its text instead of
     * storing the same message twice.
     */
    private function adoptPendingSend(Conversation $conversation, InboundMessage $event): ?Message
    {
        $isMedia = in_array($event->type, ['image', 'audio', 'video', 'document'], true);

        if ($event->source !== 'api' || ($event->body === null && ! $isMedia)) {
            return null;
        }

        return DB::transaction(function () use ($conversation, $event) {
            $alreadyStored = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('external_message_id', $event->externalId)
                ->exists();

            if ($alreadyStored) {
                return null;
            }

            $pending = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('direction', 'outbound')
                ->where('source', 'crm')
                ->whereNull('external_message_id')
                ->whereIn('status', ['queued', 'sending'])
                ->where('message_type', $isMedia ? $event->type : 'text')
                ->when(
                    $event->body !== null,
                    fn ($query) => $query->where('content', $event->body),
                    fn ($query) => $query->whereNull('content'),
                )
                ->where('created_at', '>=', now()->subMinutes(5))
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($pending === null) {
                return null;
            }

            // Once WAHA holds the file the local copy is no longer needed (and would be
            // lost on the next deploy anyway), so the message points at WAHA from now on.
            if ($event->mediaPath !== null && str_starts_with((string) $pending->media_path, 'local:')) {
                Storage::disk('local')->delete(substr((string) $pending->media_path, strlen('local:')));

                $pending->media_path = $event->mediaPath;
            }

            $pending->external_message_id = $event->externalId;
            $pending->status = 'sent';
            $pending->ack ??= 1;
            $pending->save();

            return $pending;
        });
    }

    private function ack(MessageAck $event): void
    {
        $status = $event->status();

        $messages = Message::query()
            ->whereIn('external_message_id', $event->externalIds)
            ->where('direction', 'outbound')
            ->whereHas('conversation.session', fn ($query) => $query
                ->where('channel', 'waha')
                ->where('external_id', $event->session))
            ->get();

        foreach ($messages as $message) {
            $current = self::STATUS_RANK[$message->status] ?? 0;

            $advances = $status === 'failed'
                ? $current < self::STATUS_RANK['delivered']
                : (self::STATUS_RANK[$status] ?? 0) > $current;

            if (! $advances) {
                continue;
            }

            $message->status = $status;
            $message->ack = $event->ack;

            if (in_array($status, ['delivered', 'read'], true)) {
                $message->delivered_at ??= now();
            }

            if ($status === 'read') {
                $message->read_at ??= now();
            }

            $message->save();
        }
    }
}
