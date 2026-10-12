<?php

namespace Webkul\WhatsApp\Console;

use Illuminate\Console\Command;
use Webkul\WhatsApp\Support\AppointmentReminders;

class SyncAppointmentReminders extends Command
{
    protected $signature = 'whatsapp:sync-reminders';

    protected $description = 'Schedule, move and cancel the WhatsApp reminders of upcoming appointments';

    public function handle(AppointmentReminders $reminders): int
    {
        $result = $reminders->sync();

        $this->info(sprintf('%d created, %d updated, %d cancelled.', $result['created'], $result['updated'], $result['cancelled']));

        return self::SUCCESS;
    }
}
