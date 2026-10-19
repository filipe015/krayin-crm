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
use Webkul\WhatsApp\Models\AgendaSetting;
use Webkul\WhatsApp\Support\AppointmentBooking;
use Webkul\WhatsApp\Support\AgendaVocabulary;

/**
 * The agenda: the appointments (activities of type "meeting") of the leads, week by week, with
 * the professional, the lead's funnel stage and a quick way to book one.
 */
class AgendaController extends Controller
{
    public const DURATIONS = AppointmentBooking::DURATIONS;

    public function index(): View
    {
        return view('whatsapp::agenda.index', self::bookingProps() + [
            'canCreate' => bouncer()->hasPermission('agenda.create'),
            'canOpenInbox' => bouncer()->hasPermission('whatsapp') && bouncer()->hasPermission('whatsapp.send'),
            'dayStart' => (int) AgendaSetting::current()->day_start,
            'dayEnd' => (int) AgendaSetting::current()->day_end,
            'urls' => [
                'events'   => route('admin.agenda.events'),
                'leads'    => route('admin.agenda.leads'),
                'store'    => route('admin.agenda.store'),
                'done'     => route('admin.agenda.done', ['id' => '__ID__']),
                'lead'     => route('admin.leads.view', ['id' => '__ID__']),
                'activity' => route('admin.activities.edit', ['id' => '__ID__']),
                'inbox'    => route('admin.whatsapp.inbox.open-lead', ['leadId' => '__ID__']),
            ],
        ]);
    }

    /**
     * What the booking card ("Nova consulta" / "Agendar") needs, shared by the Agenda page and the
     * lead's "Agendar" button.
     *
     * @return array<string, mixed>
     */
    public static function bookingProps(): array
    {
        $setting = AgendaSetting::current();

        return [
            'labels' => AgendaVocabulary::labels($setting),
            'defaultDuration' => (int) $setting->default_duration,
            'autoAssign' => $setting->distribution_mode !== 'manual',
            'users' => User::query()->where('status', 1)->orderBy('name')->get(['id', 'name']),
            'currentUserId' => (int) auth()->guard('user')->id(),
            'durations' => self::DURATIONS,
            'bookUrls' => [
                'leads' => route('admin.agenda.leads'),
                'store' => route('admin.agenda.store'),
            ],
        ];
    }

    public function events(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start'        => ['required', 'date_format:Y-m-d'],
            'end'          => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
            'user_ids'     => ['nullable', 'array', 'max:50'],
            'user_ids.*'   => ['integer'],
            'include_done' => ['nullable', 'boolean'],
            'include_followups' => ['nullable', 'boolean'],
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
            ->where(fn ($query) => $this->ofAgenda($query, 'activities', (bool) ($data['include_followups'] ?? true)))
            ->whereBetween('activities.schedule_from', [$from, $to])
            ->when(! ($data['include_done'] ?? false), fn ($query) => $query->where('activities.is_done', 0))
            ->when(! empty($data['user_ids']), fn ($query) => $query->whereIn('activities.user_id', $data['user_ids']))
            ->when($authorized, fn ($query) => $query->where(function ($inner) use ($authorized) {
                $inner->whereIn('activities.user_id', $authorized)
                    ->orWhereIn('activities.id', DB::table('activity_participants')->whereIn('user_id', $authorized)->select('activity_id'));
            }))
            ->orderBy('activities.schedule_from')
            ->get([
                'activities.id', 'activities.title', 'activities.comment', 'activities.location', 'activities.schedule_from', 'activities.schedule_to',
                'activities.is_done', 'activities.user_id', 'activities.type', 'users.name as user_name',
                'leads.id as lead_id', 'leads.title as lead_title', 'persons.name as person_name',
                'stages.id as stage_id', 'stages.name as stage_name',
            ]);

        return response()->json(['data' => $rows->map(fn ($row) => [
            'id'        => $row->id,
            'title'     => $row->title,
            'comment'   => $row->comment,
            'location'  => $row->location,
            'start'     => Carbon::parse($row->schedule_from)->format('Y-m-d H:i'),
            'end'       => Carbon::parse($row->schedule_to ?: $row->schedule_from)->format('Y-m-d H:i'),
            'is_done'   => (bool) $row->is_done,
            'kind'      => $row->type === 'meeting' ? 'appointment' : 'followup',
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
            'kind'            => ['nullable', Rule::in(['appointment', 'followup'])],
            'lead_id'         => ['required', 'integer', 'exists:leads,id'],
            'title'           => ['nullable', 'string', 'max:120'],
            'comment'         => ['nullable', 'string', 'max:1000'],
            'location'        => ['nullable', 'string', 'max:255'],
            'start'           => ['required', 'date_format:Y-m-d\TH:i'],
            'duration'        => ['required', 'integer', Rule::in(self::DURATIONS)],
            'user_id'         => ['required'],
            'ignore_conflict' => ['nullable', 'boolean'],
        ]);

        $authorized = bouncer()->getAuthorizedUserIds();

        abort_if(
            $authorized && ! Lead::query()->whereKey($data['lead_id'])->whereIn('user_id', $authorized)->exists(),
            403,
        );

        $result = AppointmentBooking::book($data);

        // Only a refusal carries a message: reading it on success would be an error after the booking was saved.
        if ($result['status'] === 422) {
            abort(422, $result['body']['message']);
        }

        return response()->json($result['body'], $result['status']);
    }

    /**
     * After a deal reaches a stage (moved on the board, on its page, or created there): should the
     * booking card open? Only when the installation chose that stage (Configurações → Agenda) and
     * the deal has nothing of that kind waiting yet.
     */
    public function stagePrompt(Request $request): JsonResponse
    {
        $data = $request->validate(['lead_id' => ['required', 'integer', 'exists:leads,id']]);

        $authorized = bouncer()->getAuthorizedUserIds();

        abort_if(
            $authorized && ! Lead::query()->whereKey($data['lead_id'])->whereIn('user_id', $authorized)->exists(),
            403,
        );

        $lead = Lead::query()->with(['person:id,name', 'stage:id,name'])->findOrFail($data['lead_id']);

        $kind = AgendaSetting::stageTriggers()[(int) $lead->lead_pipeline_stage_id] ?? null;

        if ($kind === null || $this->hasPending($lead->id, $kind)) {
            return response()->json(['open' => false]);
        }

        return response()->json([
            'open' => true,
            'kind' => $kind,
            'lead' => ['id' => $lead->id, 'title' => $lead->title, 'person' => $lead->person?->name, 'stage' => $lead->stage?->name],
        ]);
    }

    /**
     * A deal already waiting for an appointment (or a follow-up) does not need another one asked.
     */
    private function hasPending(int $leadId, string $kind): bool
    {
        return DB::table('activities')
            ->join('lead_activities', 'lead_activities.activity_id', '=', 'activities.id')
            ->where('lead_activities.lead_id', $leadId)
            ->where('activities.is_done', 0)
            ->where(fn ($query) => $this->ofAgenda($query, 'activities', $kind === 'followup'))
            ->when($kind === 'followup', fn ($query) => $query->where('activities.type', 'call'), fn ($query) => $query->where('activities.type', 'meeting'))
            ->exists();
    }

    public function done(int $id): JsonResponse
    {
        $query = DB::table('activities')->where('id', $id)->where(fn ($inner) => $this->ofAgenda($inner, 'activities', true));

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

    /**
     * What the Agenda lists: appointments (meetings) and, optionally, follow-ups ("retomar contato"),
     * which are calls marked with additional.kind = followup. Plain calls stay out of the Agenda.
     */
    private function ofAgenda($query, string $table, bool $withFollowups)
    {
        return $query->where(function ($inner) use ($table, $withFollowups) {
            $inner->where($table.'.type', 'meeting');

            if ($withFollowups) {
                $inner->orWhere(function ($followup) use ($table) {
                    $followup->where($table.'.type', 'call')
                        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT({$table}.additional, '$.kind')) = ?", ['followup']);
                });
            }
        });
    }
}
