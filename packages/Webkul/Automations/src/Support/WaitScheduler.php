<?php

namespace Webkul\Automations\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Automations\Jobs\ResumeAutomationWait;
use Webkul\Automations\Models\AutomationWait;

/**
 * Fase 2: what automations:tick does with the waits every minute.
 *
 *  1. recover(): a wait reserved ("queued") or resuming ("running") for more than STALE_MINUTES never
 *     got to the rest of the flow (queue down, worker killed before the walk) — safe to try again: back
 *     to "pending" with backoff (1, 2, 4, 8, 16 min); after MAX_ATTEMPTS it is "expired" (history).
 *     A wait whose flow already started is "resumed" first, so it is never picked up again.
 *  2. dispatchDue(): up to AUTOMATIONS_WAIT_BATCH due waits, oldest first. Each one is reserved with
 *     an atomic UPDATE … WHERE status = 'pending' (and a fresh claim_token) before its job is queued:
 *     two ticks at the same time (or two servers) never queue the same wait twice.
 */
final class WaitScheduler
{
    public const STALE_MINUTES = 10;

    public static function recover(): int
    {
        $stale = AutomationWait::query()
            ->whereIn('status', [AutomationWait::STATUS_QUEUED, AutomationWait::STATUS_RUNNING])
            ->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->limit(500)
            ->get();

        foreach ($stale as $wait) {
            self::release($wait, 'stale');
        }

        return $stale->count();
    }

    public static function dispatchDue(?int $batch = null): int
    {
        $batch = max(1, min(5000, $batch ?? (int) config('automations.wait_batch', 200)));

        $ids = AutomationWait::query()
            ->where('status', AutomationWait::STATUS_PENDING)
            ->where('resume_at', '<=', now())
            ->orderBy('resume_at')
            ->limit($batch)
            ->pluck('id');

        $queued = 0;

        foreach ($ids as $id) {
            $token = (string) Str::uuid();

            $claimed = AutomationWait::query()
                ->whereKey($id)
                ->where('status', AutomationWait::STATUS_PENDING)
                ->update([
                    'status' => AutomationWait::STATUS_QUEUED,
                    'claim_token' => $token,
                    'claimed_at' => now(),
                    'attempts' => DB::raw('attempts + 1'),
                    'updated_at' => now(),
                ]);

            if (! $claimed) {
                continue;
            }

            try {
                $pending = ResumeAutomationWait::dispatch((int) $id, $token);

                if ($queue = config('automations.queue')) {
                    $pending->onQueue($queue);
                }

                unset($pending);
                $queued++;
            } catch (Throwable $exception) {
                // Not queued: give it back right away (it was never started).
                AutomationWait::query()->whereKey($id)->where('claim_token', $token)->where('status', AutomationWait::STATUS_QUEUED)
                    ->update(['status' => AutomationWait::STATUS_PENDING, 'claim_token' => null, 'updated_at' => now()]);

                Log::warning('Automations: wait could not be queued.', ['wait_id' => $id, 'error' => $exception->getMessage()]);
            }
        }

        return $queued;
    }

    /**
     * Cancels the waits of a rule that have not started (pending / queued), writing the reason in each
     * run's history. Used by "Cancelar esperas pendentes", by turning the rule off and by saving a
     * changed flow. A wait already resuming re-checks the rule itself (Engine::resume).
     */
    public static function cancelForRule(int $ruleId, string $reason): int
    {
        $engine = app(Engine::class);
        $count = 0;

        AutomationWait::query()
            ->where('rule_id', $ruleId)
            ->whereIn('status', [AutomationWait::STATUS_PENDING, AutomationWait::STATUS_QUEUED])
            ->chunkById(200, function ($waits) use ($engine, $reason, &$count) {
                foreach ($waits as $wait) {
                    $engine->cancelWait($wait, $reason);
                    $count++;
                }
            });

        return $count;
    }

    /** @return array<int, int> pending waits (not started yet) by rule id */
    public static function pendingByRule(array $ruleIds): array
    {
        if ($ruleIds === []) {
            return [];
        }

        return AutomationWait::query()
            ->whereIn('rule_id', $ruleIds)
            ->whereIn('status', AutomationWait::OPEN)
            ->groupBy('rule_id')
            ->selectRaw('rule_id, count(*) as total')
            ->pluck('total', 'rule_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * A wait that did not get to run: try again later (backoff) or give up after MAX_ATTEMPTS.
     */
    public static function release(AutomationWait $wait, string $why): void
    {
        if (! in_array($wait->status, [AutomationWait::STATUS_QUEUED, AutomationWait::STATUS_RUNNING], true)) {
            return;
        }

        if ($wait->attempts >= AutomationWait::MAX_ATTEMPTS) {
            app(Engine::class)->expireWait($wait, $why);

            return;
        }

        $wait->forceFill([
            'status' => AutomationWait::STATUS_PENDING,
            'claim_token' => null,
            'resume_at' => now()->addMinutes(2 ** max(0, $wait->attempts - 1)),
        ])->save();
    }
}
