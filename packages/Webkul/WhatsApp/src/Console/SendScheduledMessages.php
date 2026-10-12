<?php

namespace Webkul\WhatsApp\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Webkul\WhatsApp\Http\Controllers\ControlHealthController;
use Webkul\WhatsApp\Jobs\SendWhatsAppMessage;
use Webkul\WhatsApp\Models\Message;
use Webkul\WhatsApp\Support\ScheduledMessage;

class SendScheduledMessages extends Command
{
    protected $signature = 'whatsapp:send-scheduled';

    protected $description = 'Send the WhatsApp messages whose scheduled time has come';

    /**
     * How long a due message waits for its number to come back before it is failed.
     */
    private const GRACE_MINUTES = 30;

    private const BATCH = 50;

    public function handle(): int
    {
        // Heartbeat the super-admin panel reads to know the scheduler is running.
        Cache::forever(ControlHealthController::SCHEDULER_CACHE_KEY, now()->toIso8601String());

        $due = Message::query()
            ->with('conversation.session:id,status')
            ->where('direction', 'outbound')
            ->where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(self::BATCH)
            ->get();

        $sent = 0;

        foreach ($due as $message) {
            $online = $message->conversation?->session?->status === 'WORKING';

            if (! $online) {
                if ($message->scheduled_at->lt(now()->subMinutes(self::GRACE_MINUTES))) {
                    ScheduledMessage::fail($message, 'The WhatsApp number was offline at the scheduled time.');
                }

                continue;
            }

            if (ScheduledMessage::release($message)) {
                SendWhatsAppMessage::dispatch($message->id);
                $sent++;
            }
        }

        $this->info($sent.' scheduled message(s) released.');

        return self::SUCCESS;
    }
}
