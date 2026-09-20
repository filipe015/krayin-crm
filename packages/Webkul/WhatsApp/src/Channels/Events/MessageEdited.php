<?php

namespace Webkul\WhatsApp\Channels\Events;

final readonly class MessageEdited
{
    public function __construct(
        public string $session,
        public string $externalId,
        public string $body,
    ) {}
}
