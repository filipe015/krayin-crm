<?php

namespace Webkul\WhatsApp\Console;

use Illuminate\Console\Command;
use Webkul\WhatsApp\Models\Message;

class FailStuckMessages extends Command
{
    protected $signature = 'whatsapp:fail-stuck-messages';

    protected $description = 'Mark outgoing WhatsApp messages that never got a confirmation as failed';

    private const MINUTES = 5;

    public function handle(): int
    {
        $failed = Message::query()
            ->where('direction', 'outbound')
            ->whereIn('status', ['queued', 'sending'])
            ->whereNull('external_message_id')
            ->where('created_at', '<', now()->subMinutes(self::MINUTES))
            ->update([
                'status'     => 'failed',
                'error'      => 'No confirmation from WhatsApp within '.self::MINUTES.' minutes.',
                'updated_at' => now(),
            ]);

        $this->info($failed.' message(s) marked as failed.');

        return self::SUCCESS;
    }
}
