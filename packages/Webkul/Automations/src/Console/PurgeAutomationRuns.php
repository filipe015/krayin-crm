<?php

namespace Webkul\Automations\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Webkul\Automations\Models\AutomationRun;

/**
 * Deletes history older than automations.retention_days (90). Runs daily. "Once per lead" keys
 * are kept: deleting them would let those rules run again on old leads.
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
                ->where(fn ($query) => $query->whereNull('idempotency_key')->orWhere('idempotency_key', 'not like', 'once:%'))
                ->limit(1000)
                ->pluck('id');

            $deleted += $ids->isEmpty() ? 0 : AutomationRun::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        $this->info("Deleted {$deleted} automation runs older than {$days} days.");

        return self::SUCCESS;
    }
}
