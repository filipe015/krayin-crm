<?php

namespace Webkul\Automations\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Webkul\Automations\Models\AutomationRun;
use Webkul\Automations\Models\AutomationWait;

/**
 * Deletes history older than automations.retention_days (90). Runs daily. "Once per lead" keys
 * are kept: deleting them would let those rules run again on old leads. Also deletes finished waits
 * older than automations.waits_retention_days (30); the time-trigger marks are kept (they are the
 * memory of "fired once per stay in the stage" and go away with their lead/rule).
 */
class PurgeAutomationRuns extends Command
{
    protected $signature = 'automations:purge {--days= : Overrides automations.retention_days}';

    protected $description = 'Delete automation history older than the retention period';

    public function handle(): int
    {
        if (! Schema::hasTable('automation_runs')) {
            $this->info('automation_runs does not exist (migrations not run): nothing to purge.');

            return self::SUCCESS;
        }

        $days = max(1, (int) ($this->option('days') ?: config('automations.retention_days', 90)));
        $limit = now()->subDays($days);
        $deleted = 0;

        do {
            $ids = AutomationRun::query()
                ->where('created_at', '<', $limit)
                ->where('status', '!=', AutomationRun::STATUS_WAITING)
                ->where(fn ($query) => $query->whereNull('idempotency_key')->orWhere('idempotency_key', 'not like', 'once:%'))
                ->limit(1000)
                ->pluck('id');

            $deleted += $ids->isEmpty() ? 0 : AutomationRun::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        $this->info("Deleted {$deleted} automation runs older than {$days} days.");

        // Fase 2: finished waits (resumed / cancelled / expired). Pending ones are never purged.
        if (Schema::hasTable('automation_waits')) {
            $waitDays = max(1, (int) config('automations.waits_retention_days', 30));
            $waitLimit = now()->subDays($waitDays);
            $waits = 0;

            do {
                $ids = AutomationWait::query()->whereIn('status', AutomationWait::FINISHED)->where('updated_at', '<', $waitLimit)->limit(1000)->pluck('id');
                $waits += $ids->isEmpty() ? 0 : AutomationWait::query()->whereIn('id', $ids)->delete();
            } while ($ids->count() === 1000);

            $this->info("Deleted {$waits} finished automation waits older than {$waitDays} days.");
        }

        return self::SUCCESS;
    }
}
