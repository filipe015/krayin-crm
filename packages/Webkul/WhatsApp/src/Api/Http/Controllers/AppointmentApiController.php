<?php

namespace Webkul\WhatsApp\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\Rule;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Lead\Models\Lead;
use Webkul\WhatsApp\Support\AppointmentBooking;

/**
 * Appointments (meetings) and follow-ups ("retomar contato") of the leads.
 */
class AppointmentApiController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'start'        => ['required', 'date'],
            'end'          => ['required', 'date', 'after_or_equal:start'],
            'user_id'      => ['nullable', 'integer'],
            'lead_id'      => ['nullable', 'integer'],
            'kind'         => ['nullable', Rule::in(['appointment', 'followup', 'all'])],
            'include_done' => ['nullable', 'boolean'],
        ]);

        $from = Carbon::parse($filters['start'], config('app.timezone'))->startOfDay();
        $to = Carbon::parse($filters['end'], config('app.timezone'))->endOfDay();

        if ($from->diffInDays($to) > 93) {
            return $this->fail('range_too_long', 'O período máximo é de 93 dias.', 422);
        }

        $kind = $filters['kind'] ?? 'all';

        $rows = DB::table('activities')
            ->leftJoin('lead_activities', 'lead_activities.activity_id', '=', 'activities.id')
            ->leftJoin('leads', 'leads.id', '=', 'lead_activities.lead_id')
            ->leftJoin('persons', 'persons.id', '=', 'leads.person_id')
            ->leftJoin('users', 'users.id', '=', 'activities.user_id')
            ->where(function ($query) use ($kind) {
                if ($kind !== 'followup') {
                    $query->orWhere('activities.type', 'meeting');
                }

                if ($kind !== 'appointment') {
                    $query->orWhere(fn ($inner) => $inner->where('activities.type', 'call')
                        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(activities.additional, '$.kind')) = ?", ['followup']));
                }
            })
            ->whereBetween('activities.schedule_from', [$from, $to])
            ->when(! ($filters['include_done'] ?? false), fn ($query) => $query->where('activities.is_done', 0))
            ->when($filters['user_id'] ?? null, fn ($query, $v) => $query->where('activities.user_id', $v))
            ->when($filters['lead_id'] ?? null, fn ($query, $v) => $query->where('lead_activities.lead_id', $v))
            ->orderBy('activities.schedule_from')
            ->limit(500)
            ->get([
                'activities.id', 'activities.type', 'activities.title', 'activities.comment', 'activities.location',
                'activities.schedule_from', 'activities.schedule_to', 'activities.is_done', 'activities.user_id',
                'users.name as user_name', 'leads.id as lead_id', 'leads.title as lead_title', 'persons.name as person_name',
            ]);

        return $this->ok($rows->map(fn ($row) => [
            'id'        => $row->id,
            'kind'      => $row->type === 'meeting' ? 'appointment' : 'followup',
            'title'     => $row->title,
            'comment'   => $row->comment,
            'location'  => $row->location,
            'start'     => Carbon::parse($row->schedule_from)->toIso8601String(),
            'end'       => Carbon::parse($row->schedule_to ?: $row->schedule_from)->toIso8601String(),
            'is_done'   => (bool) $row->is_done,
            'user'      => $row->user_id ? ['id' => $row->user_id, 'name' => $row->user_name] : null,
            'lead'      => $row->lead_id ? ['id' => $row->lead_id, 'title' => $row->lead_title, 'person' => $row->person_name] : null,
        ])->values());
    }

    public function store(Request $request, int $leadId): JsonResponse
    {
        $data = $request->validate([
            'kind'            => ['nullable', Rule::in(['appointment', 'followup'])],
            'start'           => ['required', 'date'],
            'duration'        => ['nullable', 'integer', Rule::in(AppointmentBooking::DURATIONS)],
            'user_id'         => ['required'],
            'title'           => ['nullable', 'string', 'max:120'],
            'comment'         => ['nullable', 'string', 'max:1000'],
            'location'        => ['nullable', 'string', 'max:255'],
            'ignore_conflict' => ['nullable', 'boolean'],
        ]);

        if (! Lead::query()->whereKey($leadId)->exists()) {
            return $this->fail('not_found', 'Negócio não encontrado.', 404);
        }

        // Dates without a zone are read as the installation's time; with a zone they are converted.
        $data['start'] = Carbon::parse($data['start'], config('app.timezone'))
            ->setTimezone(config('app.timezone'))
            ->format('Y-m-d\TH:i');

        $result = AppointmentBooking::book($data + ['lead_id' => $leadId]);

        return match ($result['status']) {
            409     => $this->fail('conflict', $result['body']['message'], 409, ['conflicts' => $result['body']['conflicts']]),
            422     => $this->fail('invalid', $result['body']['message'], 422),
            default => $this->ok($result['body']['data'], [], 201),
        };
    }

    public function done(int $id): JsonResponse
    {
        $exists = DB::table('activities')->where('id', $id)->whereIn('type', ['meeting', 'call'])->exists();

        if (! $exists) {
            return $this->fail('not_found', 'Compromisso não encontrado.', 404);
        }

        Event::dispatch('activity.update.before', $id);

        $activity = app(ActivityRepository::class)->update(['is_done' => 1], $id);

        Event::dispatch('activity.update.after', $activity);

        return $this->ok(['id' => $id, 'is_done' => true]);
    }
}
