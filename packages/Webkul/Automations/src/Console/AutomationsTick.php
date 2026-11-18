<?php

namespace Webkul\Automations\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Webkul\Automations\Support\EventRouter;
use Webkul\Automations\Support\TimeTriggers;
use Webkul\Automations\Support\WaitScheduler;

/**
 * Fase 2 — every minute (schedule:run): resumes the due "Esperar" blocks and fires the time triggers
 * ("lead parado na etapa", "sem atividade"). Does nothing while AUTOMATIONS_ENABLED=false or before
 * the migration ran. Needs the container's cron (docker/laravel-scheduler.cron → schedule:run) and the
 * queue worker (the work itself runs in jobs).
 */
class AutomationsTick extends Command
{
    protected $signature = 'automations:tick';

    protected $description = 'Resume due automation waits and fire the time triggers';

    public function handle(): int
    {
        if (! EventRouter::enabled()) {
            $this->info('Automations are off (AUTOMATIONS_ENABLED=false): nothing to do.');

            return self::SUCCESS;
        }

        if (! Schema::hasTable('automation_waits') || ! Schema::hasTable('automation_time_marks')) {
            $this->info('automation_waits does not exist (migrations not run): nothing to do.');

            return self::SUCCESS;
        }

        $recovered = WaitScheduler::recover();
        $resumed = WaitScheduler::dispatchDue();
        $fired = TimeTriggers::run();

        $this->info("Waits re-queued: {$recovered}; waits resumed: {$resumed}; time triggers fired: {$fired}.");

        return self::SUCCESS;
    }
}
