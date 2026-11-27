<?php

namespace Webkul\WhatsApp\Support;

use Illuminate\Support\Carbon;
use Webkul\WhatsApp\Models\Conversation;

/**
 * The Cloud API only lets free-form messages go out within 24 hours of the customer's
 * last message; outside it only an approved template can be sent.
 */
final class ConversationWindow
{
    public const HOURS = 24;

    public static function isOpen(?Carbon $lastInboundAt, ?Carbon $now = null): bool
    {
        return $lastInboundAt !== null && $lastInboundAt->gt(($now ?? now())->copy()->subHours(self::HOURS));
    }

    public static function expiresAt(Conversation $conversation): ?Carbon
    {
        return $conversation->last_inbound_at?->copy()->addHours(self::HOURS);
    }

    /**
     * Only the official channel has the 24-hour rule; the unofficial one (WAHA) can always send.
     */
    public static function isOpenFor(Conversation $conversation): bool
    {
        if (($conversation->session?->channel ?? 'cloud') !== 'cloud') {
            return true;
        }

        return self::isOpen($conversation->last_inbound_at);
    }

    public static function expiresAtFor(Conversation $conversation): ?Carbon
    {
        return ($conversation->session?->channel ?? 'cloud') !== 'cloud' ? null : self::expiresAt($conversation);
    }
}
