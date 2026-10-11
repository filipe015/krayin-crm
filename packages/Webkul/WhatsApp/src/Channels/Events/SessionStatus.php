<?php

namespace Webkul\WhatsApp\Channels\Events;

final readonly class SessionStatus
{
    public function __construct(
        public string $session,
        public string $status,
        public ?string $phone,
        public ?string $name,
        public string $channel = 'waha',
    ) {}
}
