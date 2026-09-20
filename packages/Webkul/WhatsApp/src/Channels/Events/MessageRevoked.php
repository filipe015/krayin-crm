<?php

namespace Webkul\WhatsApp\Channels\Events;

final readonly class MessageRevoked
{
    public function __construct(
        public string $session,
        public string $externalId,
    ) {}
}
