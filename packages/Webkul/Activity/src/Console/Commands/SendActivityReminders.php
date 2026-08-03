<?php

namespace Webkul\Activity\Console\Commands;

use Illuminate\Console\Command;
use Throwable;
use Webkul\Activity\Models\Activity;
use Webkul\Activity\Notifications\ActivityReminderNotification;

class SendActivityReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activities:send-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send database and email reminders for task activities approaching their deadline.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = max(1, (int) config('activity.reminders.hours', 24));
        $now = now();
        $deadline = $now->copy()->addHours($hours);
        $sent = 0;
        $failed = 0;

        Activity::query()
            ->with('user')
            ->where('type', 'task')
            ->where('is_done', false)
            ->whereNull('reminder_sent_at')
            ->whereNotNull('user_id')
            ->whereBetween('schedule_to', [$now, $deadline])
            ->orderBy('id')
            ->chunkById(100, function ($activities) use (&$sent, &$failed) {
                foreach ($activities as $activity) {
                    if (! $activity->user) {
                        $this->warn("Activity {$activity->id} was skipped because its responsible user was not found.");

                        continue;
                    }

                    try {
                        $activity->user->notify(new ActivityReminderNotification($activity));

                        $activity->forceFill([
                            'reminder_sent_at' => now(),
                        ])->save();

                        $sent++;
                    } catch (Throwable $exception) {
                        report($exception);

                        $failed++;

                        $this->error("Failed to send reminder for activity {$activity->id}: {$exception->getMessage()}");
                    }
                }
            });

        $this->info("Activity reminders processed: {$sent} sent, {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
