<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\Rule;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Lead\Models\Lead;
use Webkul\User\Models\User;

/**
 * The agenda: the appointments (activities of type "meeting") of the leads, week by week, with
 * the professional, the lead's funnel stage and a quick way to book one.
 */
class AgendaController extends Controller
{
    private const DURATIONS = [15, 20, 30, 40, 45, 50, 60, 75, 90, 120];

    public function index(): View
    {
        return view('whatsapp::agenda.index', [
            'users' => User::query()->where('status', 1)->orderBy('name')->get(['id', 'name']),
            'currentUserId' => auth()->guard('user')->id(),
            'canCreate' => bouncer()->hasPermission('agenda.create'),
            'canOpenInbox' => bouncer()->hasPermission('whatsapp') && bouncer()->hasPermission('whatsapp.send'),
            'urls' => [
                'events'   => route('admin.agenda.events'),
                'leads'    => route('admin.agenda.leads'),
                'store'    => route('admin.agenda.store'),
                'done'     => route('admin.agenda.done', ['id' => '__ID__']),
                'lead'     => route('admin.leads.view', ['id' => '__ID__']),
                'activity' => route('admin.activities.edit', ['id' => '__ID__']),
                'inbox'    => route('admin.whatsapp.inbox.open-lead', ['leadId' => '__ID__']),
            ],
            'durations' => self::DURATIONS,
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start'        => ['required', 'date_format:Y-m-d'],
            'end'          => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
            'user_ids'     => ['nullable', 'array', 'max:50'],
            'user_ids.*'   => ['integer'],
            'include_done' => ['nullable', 'boolean'],
        ]);

        $from = Carbon::parse($data['start'], config('app.timezone'))->startOfDay();
        $to = Carbon::parse($data['end'], config('app.timezone'))->endOfDay();

        // A week plus a margin: a request for a year of appointments is not a calendar view.
        abort_if($from->diffInDays($to) > 45, 422);

        $authorized = bouncer()->getAuthorizedUserIds();

        $rows = DB::table('activities')
            ->leftJoin('lead_activities', 'lead_activities.activity_id', '=', 'activities.id')
            ->leftJoin('leads', 'leads.id', '=', 'lead_activities.lead_id')
            ->leftJoin('persons', 'persons.id', '=', 'leads.person_id')
            ->leftJoin('lead_pipeline_stages as stages', 'stages.id', '=', 'leads.lead_pipeline_stage_id')
            ->leftJoin('users', 'users.id', '=', 'activities.user_id')
            ->where('activities.type', 'meeting')
            ->whereBetween('activities.schedule_from', [$from, $to])
            ->when(! ($data['include_done'] ?? false), fn ($query) => $query->where('activities.is_done', 0))
            ->when(! empty($data['user_ids']), fn ($query) => $query->whereIn('activities.user_id', $data['user_ids']))
            ->when($authorized, fn ($query) => $query->where(function ($inner) use ($authorized) {
                $inner->whereIn('activities.user_id', $authorized)
                    ->orWhereIn('activities.id', DB::table('activity_participants')->whereIn('user_id', $authorized)->select('activity_id'));
            }))
            ->orderBy('activities.schedule_from')
            ->get([
                'activities.id', 'activities.title', 'activities.comment', 'activities.schedule_from', 'activities.schedule_to',
                'activities.is_done', 'activities.user_id', 'users.name as user_name',
                'leads.id as lead_id', 'leads.title as lead_title', 'persons.name as person_name',
                'stages.id as stage_id', 'stages.name as stage_name',
            ]);

        return response()->json(['data' => $rows->map(fn ($row) => [
            'id'        => $row->id,
            'title'     => $row->title,
            'comment'   => $row->comment,
            'start'     => Carbon::parse($row->schedule_from)->format('Y-m-d H:i'),
            'end'       => Carbon::parse($row->schedule_to ?: $row->schedule_from)->format('Y-m-d H:i'),
            'is_done'   => (bool) $row->is_done,
            'user_id'   => $row->user_id,
            'user_name' => $row->user_name,
            'lead_id'   => $row->lead_id,
            'lead'      => $row->lead_title,
            'person'    => $row->person_name,
            'stage_id'  => $row->stage_id,
            'stage'     => $row->stage_name,
        ])->values()]);
    }

    public function leads(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q'));

        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $leads = Lead::query()
            ->with(['person:id,name', 'stage:id,name'])
            ->when(bouncer()->getAuthorizedUserIds(), fn ($query, $userIds) => $query->whereIn('leads.user_id', $userIds))
            ->where(fn ($query) => $query
                ->where('leads.title', 'like', $like)
                ->orWhereHas('person', fn ($person) => $person->where('name', 'like', $like)))
            ->latest('leads.id')
            ->limit(10)
            ->get(['leads.id', 'leads.title', 'leads.person_id', 'leads.lead_pipeline_stage_id']);

        return response()->json(['data' => $leads->map(fn (Lead $lead) => [
            'id'     => $lead->id,
            'title'  => $lead->title,
            'person' => $lead->person?->name,
            'stage'  => $lead->stage?->name,
        ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lead_id'         => ['required', 'integer', 'exists:leads,id'],
            'title'           => ['nullable', 'string', 'max:120'],
            'comment'         => ['nullable', 'string', 'max:1000'],
            'start'           => ['required', 'date_format:Y-m-d\TH:i'],
            'duration'        => ['required', 'integer', Rule::in(self::DURATIONS)],
            'user_id'         => ['required', 'integer', Rule::exists('users', 'id')->where('status', 1)],
            'ignore_conflict' => ['nullable', 'boolean'],
        ]);

        $authorized = bouncer()->getAuthorizedUserIds();

        abort_if(
            $authorized && ! Lead::query()->whereKey($data['lead_id'])->whereIn('user_id', $authorized)->exists(),
            403,
        );

        $start = Carbon::createFromFormat('Y-m-d\TH:i', $data['start'], config('app.timezone'));
        $end = $start->copy()->addMinutes((int) $data['duration']);

        // The same professional cannot be in two appointments at once (unless the operator insists).
        $conflicts = DB::table('activities')
            ->where('type', 'meeting')
            ->where('is_done', 0)
            ->where('user_id', $data['user_id'])
            ->where('schedule_from', '<', $end)
            ->where('schedule_to', '>', $start)
            ->get(['id', 'title', 'schedule_from', 'schedule_to']);

        if ($conflicts->isNotEmpty() && ! ($data['ignore_conflict'] ?? false)) {
            return response()->json([
                'message'   => trans('whatsapp::app.agenda.conflict'),
                'conflicts' => $conflicts->map(fn ($row) => [
                    'id'    => $row->id,
                    'title' => $row->title,
                    'start' => Carbon::parse($row->schedule_from)->format('H:i'),
                    'end'   => Carbon::parse($row->schedule_to)->format('H:i'),
                ]),
            ], 409);
        }

        Event::dispatch('activity.create.before');

        $activity = app(ActivityRepository::class)->create([
            'type'          => 'meeting',
            'title'         => trim((string) ($data['title'] ?? '')) ?: trans('whatsapp::app.agenda.default_title'),
            'comment'       => $data['comment'] ?? null,
            'schedule_from' => $start,
            'schedule_to'   => $end,
            'is_done'       => 0,
            'user_id'       => $data['user_id'],
        ]);

        // The core listener links the activity to the lead named by `lead_id` in this request.
        Event::dispatch('activity.create.after', $activity);

        return response()->json(['data' => ['id' => $activity->id]], 201);
    }

    public function done(int $id): JsonResponse
    {
        $query = DB::table('activities')->where('id', $id)->where('type', 'meeting');

        if ($authorized = bouncer()->getAuthorizedUserIds()) {
            $query->where(function ($inner) use ($authorized) {
                $inner->whereIn('user_id', $authorized)
                    ->orWhereIn('id', DB::table('activity_participants')->whereIn('user_id', $authorized)->select('activity_id'));
            });
        }

        abort_unless($query->exists(), 404);

        Event::dispatch('activity.update.before', $id);

        $activity = app(ActivityRepository::class)->update(['is_done' => 1], $id);

        Event::dispatch('activity.update.after', $activity);

        return response()->json(['done' => true]);
    }
}
