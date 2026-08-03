<?php

namespace Webkul\Activity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Webkul\Activity\Models\Activity;

class ActivityReminderNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public Activity $activity) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Task deadline reminder')
            ->greeting("Hello {$notifiable->name},")
            ->line("The task \"{$this->taskTitle()}\" is approaching its deadline.")
            ->line("Deadline: {$this->formattedDeadline()}")
            ->line('Please review the task in Krayin CRM.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'activity_id' => $this->activity->id,
            'title' => $this->taskTitle(),
            'message' => "The task \"{$this->taskTitle()}\" is approaching its deadline.",
            'schedule_to' => $this->activity->schedule_to?->toISOString(),
        ];
    }

    /**
     * Return a displayable task title.
     */
    private function taskTitle(): string
    {
        return $this->activity->title ?: "Task #{$this->activity->id}";
    }

    /**
     * Return the deadline in the application's timezone.
     */
    private function formattedDeadline(): string
    {
        return $this->activity->schedule_to
            ?->timezone(config('app.timezone'))
            ->format('Y-m-d H:i') ?? 'Not specified';
    }
}
