<?php

namespace Webkul\WhatsApp\Channels\Events;

final readonly class MessageAck
{
    /**
     * @param  list<string>  $externalIds
     */
    public function __construct(
        public string $session,
        public array $externalIds,
        public int $ack,
    ) {}

    public function status(): string
    {
        return match (true) {
            $this->ack < 0   => 'failed',
            $this->ack >= 3  => 'read',
            $this->ack === 2 => 'delivered',
            $this->ack === 1 => 'sent',
            default          => 'sending',
        };
    }
}
