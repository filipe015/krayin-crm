<?php

namespace Webkul\WhatsApp\Channels\Waha;

use Webkul\WhatsApp\Models\Conversation;

final class SendTarget
{
    /**
     * Chat ids to try, in order. The LID comes first because WhatsApp addresses
     * privacy-mode contacts by it and it never changes; the phone is the fallback.
     *
     * @return list<string>
     */
    public static function for(Conversation $conversation): array
    {
        $targets = [];

        if ($conversation->contact_lid) {
            $targets[] = $conversation->contact_lid;
        }

        if ($conversation->contact_phone) {
            $targets[] = $conversation->contact_phone.'@c.us';
        }

        return array_values(array_unique($targets));
    }
}
