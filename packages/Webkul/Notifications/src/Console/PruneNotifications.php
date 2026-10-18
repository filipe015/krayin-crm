<?php

namespace Webkul\Notifications\Console;

use Illuminate\Console\Command;
use Webkul\Notifications\Models\Notification;

class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune {--days=60}';

    protected $description = 'Deletes notifications older than the given number of days';

    public function handle(): int
    {
        $deleted = Notification::query()->where('created_at', '<', now()->subDays((int) $this->option('days')))->delete();

        $this->info("{$deleted} notification(s) deleted.");

        return self::SUCCESS;
    }
}
