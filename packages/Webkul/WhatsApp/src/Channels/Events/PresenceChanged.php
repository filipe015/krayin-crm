<?php

namespace Webkul\WhatsApp\Channels\Events;

final readonly class PresenceChanged
{
    /**
     * @param  string  $state  typing, recording, paused, online or offline
     */
    public function __construct(
        public string $session,
        public string $chatId,
        public string $state,
    ) {}
}
