<?php

namespace Webkul\Goals\Console;

use Illuminate\Console\Command;
use Webkul\Goals\Support\GoalAlerts;

class CheckGoalAlerts extends Command
{
    protected $signature = 'goals:alerts';

    protected $description = 'Warns about goals that are off pace (notification center), once a day per alert';

    public function handle(): int
    {
        $this->info(GoalAlerts::run().' alert(s) sent.');

        return self::SUCCESS;
    }
}
