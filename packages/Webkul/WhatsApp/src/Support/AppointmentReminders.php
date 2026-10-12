<?php

namespace Webkul\WhatsApp\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Activity\Models\Activity;
use Webkul\Lead\Models\Lead;
use Webkul\WhatsApp\Http\Controllers\CloudTemplateController;
use Webkul\WhatsApp\Models\Conversation;
use Webkul\WhatsApp\Models\Message;
use Webkul\WhatsApp\Models\Reminder;
use Webkul\WhatsApp\Models\ReminderSetting;
use Webkul\WhatsApp\Models\Session;

/**
 * Keeps an approved template scheduled before every upcoming appointment (an activity of
 * the configured type) of the leads that are in the configured funnel stage.
 *
 * It is a reconciliation, not an event handler: it can run at any time, as often as wanted,
 * and converges to the same result whichever order the stage change and the activity were
 * saved in.
 */
final class AppointmentReminders
{
    public const SOURCES = [
        'contact_first_name', 'contact_name', 'owner_name',
        'appointment_date', 'appointment_time', 'appointment_datetime', 'lead_title',
    ];

    public const OFFSETS = [60, 120, 240, 720, 1440, 2880, 4320];

    private const WEEKDAYS = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];

    /**
     * @return array{created: int, updated: int, cancelled: int}
     */
    public function sync(): array
    {
        $result = ['created' => 0, 'updated' => 0, 'cancelled' => 0];

        $settings = ReminderSetting::query()->first();

        if ($settings === null || ! $settings->enabled || ! $settings->stage_id || ! $settings->template_name) {
            return $result;
        }

        $session = $this->session($settings);

        if ($session === null) {
            return $result;
        }

        $template = $this->template($session, $settings);

        if ($template === null) {
            return $result;
        }

        $offsets = collect($settings->offsets ?? [])->map(fn ($value) => (int) $value)->filter(fn ($value) => $value > 0)->unique()->values()->all();
        $keep = array_map('intval', array_merge([$settings->stage_id], (array) ($settings->keep_stage_ids ?? [])));

        $this->reconcile($settings, $template, $keep, $result);
        $this->create($settings, $session, $template, $offsets, $result);

        return $result;
    }

    /**
     * Pending reminders follow their appointment: dropped when it is gone, done or the lead
     * left the stages that keep reminders, moved when the appointment moved.
     *
     * @param  array<string, mixed>  $template
     * @param  list<int>  $keep
     * @param  array{created: int, updated: int, cancelled: int}  $result
     */
    private function reconcile(ReminderSetting $settings, array $template, array $keep, array &$result): void
    {
        foreach (Reminder::query()->whereNotNull('message_id')->get() as $reminder) {
            $message = Message::query()->find($reminder->message_id);

            // Already sent, failed, or removed by an operator: leave it alone.
            if ($message === null || $message->status !== 'scheduled') {
                continue;
            }

            $activity = Activity::query()->find($reminder->activity_id);
            $lead = Lead::query()->with(['person:id,name,contact_numbers', 'user:id,name'])->find($reminder->lead_id);

            $valid = $activity !== null
                && ! $activity->is_done
                && $activity->schedule_from !== null
                && $lead !== null
                && in_array((int) $lead->lead_pipeline_stage_id, $keep, true);

            $sendAt = $valid ? $activity->schedule_from->copy()->subMinutes($reminder->offset_minutes) : null;

            if (! $valid || $sendAt->lte(now())) {
                $message->delete();
                $reminder->delete();
                $result['cancelled']++;

                continue;
            }

            if ($reminder->appointment_at->ne($activity->schedule_from)) {
                $conversation = Conversation::query()->find($message->conversation_id);
                $values = $this->values($settings, $template, $lead, $activity, $conversation);

                $message->scheduled_at = $sendAt;
                $message->content = CloudTemplateController::render((string) $template['body'], $values);
                $message->template_payload = $this->payload($template, $values);
                $message->save();

                $reminder->appointment_at = $activity->schedule_from;
                $reminder->save();
                $result['updated']++;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  list<int>  $offsets
     * @param  array{created: int, updated: int, cancelled: int}  $result
     */
    private function create(ReminderSetting $settings, Session $session, array $template, array $offsets, array &$result): void
    {
        if ($offsets === []) {
            return;
        }

        $pairs = Activity::query()
            ->join('lead_activities', 'lead_activities.activity_id', '=', 'activities.id')
            ->join('leads', 'leads.id', '=', 'lead_activities.lead_id')
            ->where('leads.lead_pipeline_stage_id', $settings->stage_id)
            ->where('activities.type', $settings->activity_type)
            ->where('activities.is_done', 0)
            ->where('activities.schedule_from', '>', now())
            ->get(['activities.id as activity_id', 'leads.id as lead_id']);

        foreach ($pairs as $pair) {
            $activity = Activity::query()->find($pair->activity_id);
            $lead = Lead::query()->with(['person:id,name,contact_numbers', 'user:id,name'])->find($pair->lead_id);

            if ($activity === null || $lead === null) {
                continue;
            }

            foreach ($offsets as $offset) {
                $sendAt = $activity->schedule_from->copy()->subMinutes($offset);

                // Too late for this one (for example a visit booked for tomorrow morning has
                // no "24 hours before"): skipped, not sent late.
                if ($sendAt->lte(now()->addMinute())) {
                    continue;
                }

                if (Reminder::query()->where('activity_id', $activity->id)->where('offset_minutes', $offset)->exists()) {
                    continue;
                }

                $conversation = $this->conversation($lead, $session);

                if ($conversation === null) {
                    continue;
                }

                try {
                    $this->schedule($settings, $template, $lead, $activity, $conversation, $offset, $sendAt);
                    $result['created']++;
                } catch (QueryException $exception) {
                    // Another run created it first (unique key): nothing to do.
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function schedule(ReminderSetting $settings, array $template, Lead $lead, Activity $activity, Conversation $conversation, int $offset, Carbon $sendAt): void
    {
        $values = $this->values($settings, $template, $lead, $activity, $conversation);

        DB::transaction(function () use ($template, $values, $lead, $activity, $conversation, $offset, $sendAt) {
            $message = Message::query()->create([
                'conversation_id'  => $conversation->id,
                'direction'        => 'outbound',
                'status'           => 'scheduled',
                'scheduled_at'     => $sendAt,
                'scheduled_title'  => Str::limit(trans('whatsapp::app.inbox.reminder_title', ['when' => self::offsetLabel($offset)]), 100, ''),
                'source'           => 'crm',
                'content'          => CloudTemplateController::render((string) $template['body'], $values),
                'message_type'     => 'template',
                'template_payload' => $this->payload($template, $values),
                'sender_name'      => trans('whatsapp::app.inbox.reminder_sender'),
            ]);

            Reminder::query()->create([
                'activity_id'     => $activity->id,
                'lead_id'         => $lead->id,
                'conversation_id' => $conversation->id,
                'offset_minutes'  => $offset,
                'message_id'      => $message->id,
                'appointment_at'  => $activity->schedule_from,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  list<string>  $values
     * @return array{name: string, language: string, components: list<array<string, mixed>>}
     */
    private function payload(array $template, array $values): array
    {
        return [
            'name'       => (string) $template['name'],
            'language'   => (string) $template['language'],
            'components' => $values === [] ? [] : [[
                'type'       => 'body',
                'parameters' => array_map(fn ($value) => ['type' => 'text', 'text' => $value], $values),
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $template
     * @return list<string>
     */
    private function values(ReminderSetting $settings, array $template, Lead $lead, Activity $activity, ?Conversation $conversation): array
    {
        $sources = array_values((array) ($settings->variables ?? []));
        $values = [];

        for ($index = 0; $index < (int) $template['variables']; $index++) {
            $values[] = self::resolve((string) ($sources[$index] ?? ''), $lead, $activity, $conversation);
        }

        return $values;
    }

    /**
     * The text a template variable receives. Never empty, because the Cloud API refuses
     * empty parameters.
     */
    public static function resolve(string $source, Lead $lead, Activity $activity, ?Conversation $conversation): string
    {
        $when = $activity->schedule_from?->copy()->setTimezone(config('app.timezone'));
        $name = trim((string) ($lead->person?->name ?: $conversation?->contact_name ?: ''));

        $value = match ($source) {
            'contact_first_name'   => Str::before($name, ' '),
            'contact_name'         => $name,
            'owner_name'           => (string) ($lead->user?->name ?: $activity->user?->name ?: ''),
            'appointment_date'     => $when?->format('d/m') ?? '',
            'appointment_time'     => $when !== null ? self::time($when) : '',
            'appointment_datetime' => $when !== null ? self::WEEKDAYS[$when->dayOfWeek].', '.$when->format('d/m').' às '.self::time($when) : '',
            'lead_title'           => (string) $lead->title,
            default                => '',
        };

        $value = trim($value);

        return $value !== '' ? $value : match ($source) {
            'contact_first_name', 'contact_name' => 'cliente',
            'owner_name'                         => 'nossa equipe',
            default                              => '-',
        };
    }

    private static function time(Carbon $when): string
    {
        return $when->minute === 0 ? $when->format('G').'h' : $when->format('G\hi');
    }

    public static function offsetLabel(int $minutes): string
    {
        return $minutes % 1440 === 0
            ? trans_choice('whatsapp::app.inbox.reminder_days', intdiv($minutes, 1440), ['count' => intdiv($minutes, 1440)])
            : trans_choice('whatsapp::app.inbox.reminder_hours', max(1, intdiv($minutes, 60)), ['count' => max(1, intdiv($minutes, 60))]);
    }

    private function session(ReminderSetting $settings): ?Session
    {
        return Session::query()
            ->where('channel', 'cloud')
            ->where('status', 'WORKING')
            ->when($settings->session_id, fn ($query) => $query->whereKey($settings->session_id))
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function template(Session $session, ReminderSetting $settings): ?array
    {
        try {
            $found = collect(CloudTemplateController::approved($session))
                ->first(fn ($item) => $item['name'] === $settings->template_name && $item['language'] === $settings->template_language);
        } catch (\Throwable $exception) {
            return null;
        }

        return $found !== null && $found['supported'] ? $found : null;
    }

    /**
     * The conversation the reminder goes to: the lead's own on this number, a chat with the
     * lead's phone nobody linked yet, or a new one (a template may open a conversation).
     */
    private function conversation(Lead $lead, Session $session): ?Conversation
    {
        $existing = Conversation::query()->where('session_id', $session->id)->where('lead_id', $lead->id)->orderByDesc('id')->first();

        if ($existing !== null && filled($existing->contact_phone)) {
            return $existing;
        }

        $numbers = $lead->person?->contact_numbers;
        $first = is_array($numbers) ? ($numbers[0]['value'] ?? null) : null;
        $digits = preg_replace('/\D/', '', (string) $first);

        if ($digits === '') {
            return null;
        }

        $phone = str_starts_with($digits, '55') ? $digits : '55'.$digits;

        $unlinked = Conversation::query()
            ->where('session_id', $session->id)
            ->whereNull('lead_id')
            ->whereIn('contact_phone', PhoneNumberMatcher::variants($phone))
            ->orderByDesc('last_message_at')
            ->first();

        if ($unlinked !== null) {
            $unlinked->lead_id = $lead->id;
            $unlinked->save();

            return $unlinked;
        }

        return Conversation::query()->firstOrCreate(
            ['session_id' => $session->id, 'chat_id' => $phone.'@c.us'],
            [
                'canal_origem'     => $session->channel,
                'contact_phone'    => $phone,
                'contact_name'     => $lead->person?->name,
                'lead_id'          => $lead->id,
                'assigned_user_id' => $lead->user_id,
                'status'           => 'open',
            ],
        );
    }
}
