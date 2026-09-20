<?php

namespace Webkul\WhatsApp\Channels\Waha;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Webkul\WhatsApp\Channels\Events\InboundMessage;
use Webkul\WhatsApp\Channels\Events\MessageAck;
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

    public function handle(InboundMessage|MessageAck|SessionStatus $event): void
    {
        match (true) {
            $event instanceof InboundMessage => $this->message($event),
            $event instanceof MessageAck     => $this->ack($event),
            $event instanceof SessionStatus  => $this->sessionStatus($event),
        };
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

        $message = Message::query()->createOrFirst(
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
