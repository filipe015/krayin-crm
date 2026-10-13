<?php

namespace Webkul\WhatsApp\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\User\Models\User;
use Webkul\WhatsApp\Models\AgendaSetting;

/**
 * Books an appointment (activity "meeting") or a follow-up ("retomar contato", a call marked
 * additional.kind = followup) for a lead. Shared by the Agenda screen and the REST API, so both
 * apply the same rules: responsible distribution, conflict check and activity linking.
 */
final class AppointmentBooking
{
    public const DURATIONS = [15, 20, 30, 40, 45, 50, 60, 75, 90, 120];

    /**
     * @param  array{lead_id: int, kind?: string, title?: ?string, comment?: ?string, location?: ?string, start: string, duration?: int, user_id: int|string, ignore_conflict?: bool}  $data
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function book(array $data): array
    {
        $setting = AgendaSetting::current();
        $labels = AgendaVocabulary::labels($setting);

        $followup = ($data['kind'] ?? 'appointment') === 'followup';
        $automatic = ! $followup && ($data['user_id'] ?? null) === 'auto' && $setting->distribution_mode !== 'manual';

        if (! $automatic) {
            $valid = is_numeric($data['user_id'] ?? null)
                && User::query()->whereKey((int) $data['user_id'])->where('status', 1)->exists();

            if (! $valid) {
                return ['status' => 422, 'body' => ['message' => trans('whatsapp::app.agenda_settings.invalid_user')]];
            }
        }

        $start = Carbon::createFromFormat('Y-m-d\TH:i', $data['start'], config('app.timezone'));
        $duration = $followup ? 15 : (int) ($data['duration'] ?? $setting->default_duration);
        $end = $start->copy()->addMinutes($duration);

        if ($followup) {
            return self::saveFollowup($data, $start, $end, $labels);
        }

        $userId = $automatic ? self::pickResponsible($setting, $start, $end) : (int) $data['user_id'];

        if ($userId === null) {
            return ['status' => 422, 'body' => ['message' => trans('whatsapp::app.agenda_settings.no_pool')]];
        }

        // The same person cannot be in two appointments at once (unless the caller insists).
        $conflicts = DB::table('activities')
            ->where('type', 'meeting')
            ->where('is_done', 0)
            ->where('user_id', $userId)
            ->where('schedule_from', '<', $end)
            ->where('schedule_to', '>', $start)
            ->get(['id', 'title', 'schedule_from', 'schedule_to']);

        if ($conflicts->isNotEmpty() && ! ($data['ignore_conflict'] ?? false)) {
            return ['status' => 409, 'body' => [
                'message'   => $labels['conflict'],
                'conflicts' => $conflicts->map(fn ($row) => [
                    'id'    => $row->id,
                    'title' => $row->title,
                    'start' => Carbon::parse($row->schedule_from)->format('H:i'),
                    'end'   => Carbon::parse($row->schedule_to)->format('H:i'),
                ])->all(),
            ]];
        }

        $activity = self::create([
            'type'          => 'meeting',
            'title'         => trim((string) ($data['title'] ?? '')) ?: $labels['default_title'],
            'comment'       => $data['comment'] ?? null,
            'location'      => trim((string) ($data['location'] ?? '')) ?: null,
            'schedule_from' => $start,
            'schedule_to'   => $end,
            'is_done'       => 0,
            'user_id'       => $userId,
        ], (int) $data['lead_id']);

        if ($automatic && $setting->exists) {
            $setting->forceFill(['last_assigned_user_id' => $userId])->save();
        }

        return ['status' => 201, 'body' => ['data' => ['id' => $activity->id, 'user_id' => $userId]]];
    }

    /**
     * A follow-up is a reminder for a person to get back to the lead on a date: no distribution,
     * no conflict check and no message to the lead. It is a call, so it also shows in the lead's
     * activities.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function saveFollowup(array $data, Carbon $start, Carbon $end, array $labels): array
    {
        $activity = self::create([
            'type'          => 'call',
            'title'         => trim((string) ($data['title'] ?? '')) ?: $labels['followup_title'],
            'comment'       => $data['comment'] ?? null,
            'additional'    => json_encode(['kind' => 'followup']),
            'schedule_from' => $start,
            'schedule_to'   => $end,
            'is_done'       => 0,
            'user_id'       => (int) $data['user_id'],
        ], (int) $data['lead_id']);

        return ['status' => 201, 'body' => ['data' => ['id' => $activity->id, 'user_id' => (int) $data['user_id']]]];
    }

    private static function create(array $attributes, int $leadId)
    {
        // The core listener links the activity to the lead named by `lead_id` in the request.
        request()->merge(['lead_id' => $leadId]);

        Event::dispatch('activity.create.before');

        $activity = app(ActivityRepository::class)->create($attributes);

        Event::dispatch('activity.create.after', $activity);

        return $activity;
    }

    /**
     * Who takes an appointment nobody chose a person for, following the installation's rule and
     * skipping people who already have something at that time.
     */
    private static function pickResponsible(AgendaSetting $setting, Carbon $start, Carbon $end): ?int
    {
        $pool = array_map('intval', (array) ($setting->distribution_user_ids ?? []));

        $pool = User::query()
            ->where('status', 1)
            ->when($pool !== [], fn ($query) => $query->whereIn('id', $pool))
            ->pluck('id')
            ->all();

        if ($pool === []) {
            return null;
        }

        $unavailable = DB::table('activities')
            ->where('type', 'meeting')
            ->where('is_done', 0)
            ->whereIn('user_id', $pool)
            ->where('schedule_from', '<', $end)
            ->where('schedule_to', '>', $start)
            ->pluck('user_id')
            ->all();

        $busy = DB::table('activities')
            ->where('type', 'meeting')
            ->where('is_done', 0)
            ->whereIn('user_id', $pool)
            ->whereBetween('schedule_from', [$start->copy()->startOfDay(), $start->copy()->endOfDay()])
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id')
            ->map(fn ($total) => (int) $total)
            ->all();

        return AgendaAssignment::pick(
            $setting->distribution_mode,
            $pool,
            $setting->last_assigned_user_id ? (int) $setting->last_assigned_user_id : null,
            $busy,
            $unavailable,
        );
    }
}
