<?php

namespace Webkul\WhatsApp\Channels\Events;

use DateTimeImmutable;

final readonly class InboundMessage
{
    public function __construct(
        public string $session,
        public string $chatId,
        public ?string $phone,
        public ?string $lid,
        public ?string $contactName,
        public string $externalId,
        public bool $fromMe,
        public string $source,
        public string $type,
        public ?string $body,
        public ?string $mediaPath,
        public ?string $mediaMime,
        public ?string $mediaFilename,
        public DateTimeImmutable $sentAt,
        public string $channel = 'waha',
        public bool $buttonReply = false,
    ) {}
}
