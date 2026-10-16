<?php

namespace Webkul\WhatsApp\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Attribute\Repositories\AttributeValueRepository;
use Webkul\Contact\Models\Person;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Lead\Repositories\PipelineRepository;
use Webkul\Lead\Repositories\StageRepository;
use Webkul\WhatsApp\Api\Support\LeadPresenter;
use Webkul\WhatsApp\Api\Support\WebhookEvents;
use Webkul\WhatsApp\Support\PhoneNumberMatcher;

class LeadApiController extends ApiController
{
    public function __construct(
        protected LeadRepository $leads,
        protected PipelineRepository $pipelines,
        protected StageRepository $stages,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q'              => ['nullable', 'string', 'max:100'],
            'pipeline_id'    => ['nullable', 'integer'],
            'stage_id'       => ['nullable', 'integer'],
            'status'         => ['nullable', Rule::in(['open', 'won', 'lost'])],
            'source_id'      => ['nullable', 'integer'],
            'user_id'        => ['nullable', 'integer'],
            'email'          => ['nullable', 'string', 'max:150'],
            'phone'          => ['nullable', 'string', 'max:40'],
            'created_from'   => ['nullable', 'date'],
            'created_to'     => ['nullable', 'date'],
            'closed_from'    => ['nullable', 'date'],
            'closed_to'      => ['nullable', 'date'],
            'updated_since'  => ['nullable', 'date'],
            'per_page'       => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Lead::query()
            ->leftJoin('lead_pipeline_stages as stages', 'stages.id', '=', 'leads.lead_pipeline_stage_id')
            ->select('leads.*')
            ->when($filters['pipeline_id'] ?? null, fn ($q, $v) => $q->where('leads.lead_pipeline_id', $v))
            ->when($filters['stage_id'] ?? null, fn ($q, $v) => $q->where('leads.lead_pipeline_stage_id', $v))
            ->when($filters['source_id'] ?? null, fn ($q, $v) => $q->where('leads.lead_source_id', $v))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('leads.user_id', $v))
            ->when($filters['created_from'] ?? null, fn ($q, $v) => $q->where('leads.created_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($filters['created_to'] ?? null, fn ($q, $v) => $q->where('leads.created_at', '<=', Carbon::parse($v)->endOfDay()))
            ->when($filters['closed_from'] ?? null, fn ($q, $v) => $q->where('leads.closed_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($filters['closed_to'] ?? null, fn ($q, $v) => $q->where('leads.closed_at', '<=', Carbon::parse($v)->endOfDay()))
            ->when($filters['updated_since'] ?? null, fn ($q, $v) => $q->where('leads.updated_at', '>=', Carbon::parse($v)))
            ->when($filters['status'] ?? null, function ($q, $status) {
                $status === 'open'
                    ? $q->whereNotIn('stages.code', ['won', 'lost'])
                    : $q->where('stages.code', $status);
            })
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';

                $q->where(fn ($inner) => $inner
                    ->where('leads.title', 'like', $like)
                    ->orWhereIn('leads.person_id', DB::table('persons')->where('name', 'like', $like)->select('id')));
            })
            ->when($filters['email'] ?? null, fn ($q, $v) => $q->whereIn('leads.person_id', $this->personsByEmail($v)->select('id')))
            ->when($filters['phone'] ?? null, fn ($q, $v) => $q->whereIn('leads.person_id', $this->personIdsByPhone($v) ?: [0]))
            ->orderByDesc('leads.id');

        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return $this->ok(
            $page->getCollection()->map(fn (Lead $lead) => LeadPresenter::make($lead))->values(),
            ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        );
    }

    public function show(int $id): JsonResponse
    {
        $lead = Lead::query()->find($id);

        if (! $lead) {
            return $this->fail('not_found', 'Negócio não encontrado.', 404);
        }

        $data = LeadPresenter::make($lead);

        $data['activities'] = DB::table('lead_activities')
            ->join('activities', 'activities.id', '=', 'lead_activities.activity_id')
            ->where('lead_activities.lead_id', $id)
            ->where('activities.type', '<>', 'system')
            ->orderByDesc('activities.id')
            ->limit(50)
            ->get(['activities.id', 'activities.type', 'activities.title', 'activities.comment', 'activities.location',
                'activities.schedule_from', 'activities.schedule_to', 'activities.is_done', 'activities.user_id', 'activities.created_at'])
            ->map(fn ($row) => [
                'id'            => $row->id,
                'type'          => $row->type,
                'title'         => $row->title,
                'comment'       => $row->comment,
                'location'      => $row->location,
                'schedule_from' => $row->schedule_from ? Carbon::parse($row->schedule_from)->toIso8601String() : null,
                'schedule_to'   => $row->schedule_to ? Carbon::parse($row->schedule_to)->toIso8601String() : null,
                'is_done'       => (bool) $row->is_done,
                'user_id'       => $row->user_id,
                'created_at'    => Carbon::parse($row->created_at)->toIso8601String(),
            ]);

        return $this->ok($data);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'               => ['required', 'string', 'max:255'],
            'description'         => ['nullable', 'string', 'max:5000'],
            'value'               => ['nullable', 'numeric', 'min:0'],
            'pipeline_id'         => ['nullable', 'integer', 'exists:lead_pipelines,id'],
            'stage_id'            => ['nullable', 'integer', 'exists:lead_pipeline_stages,id'],
            'source_id'           => ['nullable', 'integer', 'exists:lead_sources,id'],
            'type_id'             => ['nullable', 'integer', 'exists:lead_types,id'],
            'user_id'             => ['nullable', 'integer', 'exists:users,id'],
            'expected_close_date' => ['nullable', 'date'],
            'person_id'           => ['nullable', 'integer', 'exists:persons,id'],
            'person'              => ['nullable', 'array'],
            'person.name'         => ['required_without:person_id', 'nullable', 'string', 'max:255'],
            'person.email'        => ['nullable', 'email', 'max:150'],
            'person.phone'        => ['nullable', 'string', 'max:40'],
            'custom'              => ['nullable', 'array'],
            'on_duplicate'        => ['nullable', Rule::in(['create', 'return'])],
            'tags'                => ['nullable', 'array', 'max:50'],
            'tags.*'              => ['integer'],
            // Imports: where it came from (repeating the call does not duplicate), original dates, no webhooks.
            'external'            => ['nullable', 'array'],
            'external.source'     => ['required_with:external', 'string', 'max:40'],
            'external.id'         => ['required_with:external', 'string', 'max:100'],
            'created_at'          => ['nullable', 'date'],
            'updated_at'          => ['nullable', 'date'],
            'closed_at'           => ['nullable', 'date'],
            'stage_entered_at'    => ['nullable', 'date'],
            'lost_reason'         => ['nullable', 'string', 'max:500'],
            'silent'              => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('silent')) {
            WebhookEvents::mute();
        }

        // Already imported: the lead that exists is returned, nothing is created.
        if (! empty($data['external'])) {
            $leadId = DB::table('api_external_refs')
                ->where('source', $data['external']['source'])
                ->where('external_id', $data['external']['id'])
                ->where('entity_type', 'lead')
                ->value('entity_id');

            $known = $leadId ? Lead::query()->find($leadId) : null;

            if ($known) {
                return $this->ok(LeadPresenter::make($known) + ['created' => false, 'external_existed' => true]);
            }
        }

        $tagIds = $this->tagIds($data['tags'] ?? []);

        if ($missing = $this->missingTags($tagIds)) {
            return $this->invalidTags($missing);
        }

        // The same person, found by e-mail or phone, is reused instead of duplicated.
        $personId = $data['person_id'] ?? null;
        $personExisted = (bool) $personId;

        if (! $personId && ! empty($data['person'])) {
            $personId = $this->findPerson($data['person']['email'] ?? null, $data['person']['phone'] ?? null);
            $personExisted = (bool) $personId;
        }

        // A person with neither e-mail nor phone: Krayin's own unique key would be empty and collide with
        // the next such person, so each one gets a key of its own.
        if (! $personId && ! empty($data['person']) && empty($data['person']['email']) && empty($data['person']['phone'])) {
            $personId = $this->createContactlessPerson((string) $data['person']['name']);
        }

        // "on_duplicate: return": an open lead of that person comes back instead of a second one.
        if ($personId && ($data['on_duplicate'] ?? 'create') === 'return') {
            $existing = Lead::query()
                ->join('lead_pipeline_stages as stages', 'stages.id', '=', 'leads.lead_pipeline_stage_id')
                ->where('leads.person_id', $personId)
                ->whereNotIn('stages.code', ['won', 'lost'])
                ->orderByDesc('leads.id')
                ->first(['leads.*']);

            if ($existing) {
                // A resubmission (same phone/e-mail) still adds its tags, keeping the ones already there.
                $this->attachTags($existing, $tagIds);

                return $this->ok(LeadPresenter::make($existing) + ['created' => false, 'person_existed' => true]);
            }
        }

        if (! empty($data['stage_id'])) {
            $stage = $this->stages->findOrFail($data['stage_id']);
            $pipelineId = $stage->lead_pipeline_id;
        } else {
            $pipeline = ! empty($data['pipeline_id'])
                ? $this->pipelines->findOrFail($data['pipeline_id'])
                : $this->pipelines->getDefaultPipeline();

            $stage = $pipeline->stages()->orderBy('sort_order')->first();
            $pipelineId = $pipeline->id;
        }

        $payload = [
            'title'                  => $data['title'],
            'description'            => $data['description'] ?? null,
            'lead_value'             => $data['value'] ?? 0,
            'status'                 => 1,
            'lead_pipeline_id'       => $pipelineId,
            'lead_pipeline_stage_id' => $stage->id,
            'lead_source_id'         => $data['source_id'] ?? null,
            'lead_type_id'           => $data['type_id'] ?? null,
            'user_id'                => $data['user_id'] ?? null,
            'expected_close_date'    => $data['expected_close_date'] ?? null,
            'entity_type'            => 'leads',
        ];

        if (in_array($stage->code, ['won', 'lost'], true)) {
            $payload['closed_at'] = Carbon::now();
        }

        if ($stage->code === 'lost' && ! empty($data['lost_reason'])) {
            $payload['lost_reason'] = $data['lost_reason'];
        }

        if ($personId) {
            $payload['person'] = ['id' => $personId];
        } else {
            $person = $data['person'];

            $payload['person'] = array_filter([
                'name'            => $person['name'],
                'emails'          => ! empty($person['email']) ? [['value' => $person['email'], 'label' => 'work']] : null,
                'contact_numbers' => ! empty($person['phone']) ? [['value' => $person['phone'], 'label' => 'work']] : null,
            ]);
        }

        // Custom fields, by code, only the ones defined for leads.
        if (! empty($data['custom'])) {
            $known = DB::table('attributes')->where('entity_type', 'leads')->where('is_user_defined', 1)->pluck('code')->all();

            $payload += array_intersect_key($data['custom'], array_flip($known));
        }

        Event::dispatch('lead.create.before');

        $lead = $this->leads->create($payload);

        $this->attachTags($lead, $tagIds);

        $this->applyOriginalDates($lead, $data);

        if (! empty($data['external'])) {
            DB::table('api_external_refs')->insertOrIgnore([
                'source'      => $data['external']['source'],
                'external_id' => $data['external']['id'],
                'entity_type' => 'lead',
                'entity_id'   => $lead->id,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        Event::dispatch('lead.create.after', $lead);

        return $this->ok(LeadPresenter::make($lead->refresh()) + ['created' => true, 'person_existed' => $personExisted], [], 201);
    }

    public function stage(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'stage_id'    => ['required', 'integer'],
            'lost_reason' => ['nullable', 'string', 'max:500'],
            'value'       => ['nullable', 'numeric', 'min:0'],
        ]);

        $lead = Lead::query()->find($id);

        if (! $lead) {
            return $this->fail('not_found', 'Negócio não encontrado.', 404);
        }

        // A lead moves inside its own funnel.
        $stage = $lead->pipeline->stages()->where('id', $data['stage_id'])->first();

        if (! $stage) {
            return $this->fail('invalid_stage', 'Esta etapa não pertence ao funil do negócio.', 422);
        }

        $payload = ['lead_pipeline_stage_id' => $stage->id, 'entity_type' => 'leads'];

        if (in_array($stage->code, ['won', 'lost'], true)) {
            $payload['closed_at'] = Carbon::now();
        }

        if ($stage->code === 'lost' && ! empty($data['lost_reason'])) {
            $payload['lost_reason'] = $data['lost_reason'];
        }

        if ($stage->code === 'won' && isset($data['value'])) {
            $payload['lead_value'] = $data['value'];
        }

        Event::dispatch('lead.update.before', $id);

        $lead = $this->leads->update($payload, $id, ['lead_pipeline_stage_id']);

        Event::dispatch('lead.update.after', $lead);

        return $this->ok(LeadPresenter::make($lead->refresh()));
    }

    public function note(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'comment'    => ['required', 'string', 'max:5000'],
            'created_at' => ['nullable', 'date'],
            'silent'     => ['nullable', 'boolean'],
        ]);

        $lead = Lead::query()->find($id);

        if (! $lead) {
            return $this->fail('not_found', 'Negócio não encontrado.', 404);
        }

        if ($request->boolean('silent')) {
            WebhookEvents::mute();
        }

        Event::dispatch('activity.create.before');

        $activity = app(ActivityRepository::class)->create([
            'type'    => 'note',
            'comment' => $data['comment'],
            'is_done' => 1,
            'user_id' => $lead->user_id,
        ]);

        $lead->activities()->attach($activity->id);

        // An imported note keeps the date it was written.
        if (! empty($data['created_at'])) {
            DB::table('activities')->where('id', $activity->id)->update([
                'created_at' => Carbon::parse($data['created_at']),
                'updated_at' => Carbon::parse($data['created_at']),
            ]);
        }

        Event::dispatch('activity.create.after', $activity);

        return $this->ok(['id' => $activity->id], [], 201);
    }

    /**
     * Imports keep the dates of the original record: the sales-cycle report reads created_at and
     * closed_at, and "days in stage" reads stage_entered_at.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyOriginalDates(Lead $lead, array $data): void
    {
        $columns = [];

        foreach (['created_at', 'updated_at', 'closed_at', 'stage_entered_at'] as $column) {
            if (! empty($data[$column])) {
                $columns[$column] = Carbon::parse($data[$column]);
            }
        }

        if (! $columns) {
            return;
        }

        // A lead imported as "created in March" was last touched no earlier than that.
        $columns += ['updated_at' => $columns['created_at'] ?? $columns['closed_at'] ?? now()];
        $columns += isset($columns['created_at']) ? ['stage_entered_at' => $columns['created_at']] : [];

        DB::table('leads')->where('id', $lead->id)->update($columns);

        // The "lead created" entry of the timeline is dated like the lead.
        if (isset($columns['created_at'])) {
            DB::table('activities')
                ->whereIn('id', DB::table('lead_activities')->where('lead_id', $lead->id)->select('activity_id'))
                ->where('type', 'system')
                ->update(['created_at' => $columns['created_at'], 'updated_at' => $columns['created_at']]);
        }
    }

    /**
     * Adds tags to a lead; the ones it already has stay (and are not duplicated).
     */
    public function tags(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'tags'   => ['required', 'array', 'min:1', 'max:50'],
            'tags.*' => ['integer'],
        ]);

        $lead = Lead::query()->find($id);

        if (! $lead) {
            return $this->fail('not_found', 'Negócio não encontrado.', 404);
        }

        $tagIds = $this->tagIds($data['tags']);

        if ($missing = $this->missingTags($tagIds)) {
            return $this->invalidTags($missing);
        }

        $this->attachTags($lead, $tagIds);

        return $this->show($id);
    }

    /**
     * @return list<int>
     */
    private function tagIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function missingTags(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $found = DB::table('tags')->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_diff($ids, $found));
    }

    private function invalidTags(array $missing): JsonResponse
    {
        return $this->fail('invalid_tags', 'Etiqueta(s) inexistente(s): '.implode(', ', $missing).'. Veja GET /tags.', 422, ['tags' => $missing]);
    }

    /**
     * @param  list<int>  $ids
     */
    private function attachTags(Lead $lead, array $ids): void
    {
        if (! $ids) {
            return;
        }

        $changes = $lead->tags()->syncWithoutDetaching($ids);

        // Tag changes count as an update, so "GET /leads?updated_since=" picks them up.
        if ($changes['attached']) {
            $lead->touch();
        }

        $lead->unsetRelation('tags');
    }

    private function createContactlessPerson(string $name): int
    {
        $person = Person::query()->create(['name' => $name, 'unique_id' => 'api|'.Str::uuid()]);

        app(AttributeValueRepository::class)->save([
            'entity_type' => 'persons',
            'name'        => $name,
            'entity_id'   => $person->id,
        ]);

        return (int) $person->id;
    }

    /**
     * Person by e-mail first, then by phone (any formatting).
     */
    private function findPerson(?string $email, ?string $phone): ?int
    {
        if ($email) {
            $id = $this->personsByEmail($email)->orderBy('id')->value('id');

            if ($id) {
                return (int) $id;
            }
        }

        if ($phone) {
            return $this->personIdsByPhone($phone)[0] ?? null;
        }

        return null;
    }

    private function personsByEmail(string $email)
    {
        return DB::table('persons')->whereRaw(
            "JSON_SEARCH(LOWER(emails), 'one', ?, NULL, '$[*].value') IS NOT NULL",
            [mb_strtolower(trim($email))],
        );
    }

    /**
     * @return list<int>
     */
    private function personIdsByPhone(string $phone): array
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) < 8) {
            return [];
        }

        // The last digits narrow the search in SQL; the matcher then confirms (country code, 9th digit).
        return DB::table('persons')
            ->where('contact_numbers', 'like', '%'.substr($digits, -4).'%')
            ->orderBy('id')
            ->get(['id', 'contact_numbers'])
            ->filter(function ($person) use ($phone) {
                foreach ((array) json_decode((string) $person->contact_numbers, true) as $number) {
                    $value = is_array($number) ? ($number['value'] ?? '') : $number;

                    if ($value !== '' && PhoneNumberMatcher::matches((string) $value, $phone)) {
                        return true;
                    }
                }

                return false;
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
