<?php

namespace Webkul\Automations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Automations\Models\AutomationWait;
use Webkul\Automations\Support\Engine;
use Webkul\Automations\Support\WaitScheduler;

/**
 * Resumes one paused flow. The token is the one automations:tick wrote when it reserved the wait: a
 * job whose token no longer matches (the wait was re-queued, cancelled or already ran) does nothing,
 * so a second delivery never runs the flow twice. One attempt per job; a failure BEFORE the rest of
 * the flow starts sends the wait back to "pending" with backoff (WaitScheduler::release).
 */
class ResumeAutomationWait implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $waitId, public string $token) {}

    public function handle(Engine $engine): void
    {
        $claimed = AutomationWait::query()
            ->whereKey($this->waitId)
            ->where('status', AutomationWait::STATUS_QUEUED)
            ->where('claim_token', $this->token)
            ->update(['status' => AutomationWait::STATUS_RUNNING, 'claimed_at' => now(), 'updated_at' => now()]);

        if (! $claimed) {
            return;
        }

        $wait = AutomationWait::query()->find($this->waitId);

        if (! $wait) {
            return;
        }

        try {
            $engine->resume($wait);
        } catch (Throwable $exception) {
            Log::warning('Automations: wait could not be resumed.', ['wait_id' => $this->waitId, 'error' => $exception->getMessage()]);

            WaitScheduler::release($wait->refresh(), $exception->getMessage());
        }
    }
}
