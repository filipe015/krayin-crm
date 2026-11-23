<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Models\Pipeline;
use Webkul\Lead\Models\Stage;
use Webkul\Lead\Models\Type;
use Webkul\Lead\Repositories\PipelineRepository;
use Webkul\WhatsApp\Models\Conversation;
use Webkul\WhatsApp\Support\ConversationLead;

class InboxLeadController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q'));

        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $leads = Lead::query()
            ->with('person:id,name')
            ->when(bouncer()->getAuthorizedUserIds(), fn ($query, $userIds) => $query->whereIn('leads.user_id', $userIds))
            ->where(fn ($query) => $query
                ->where('leads.title', 'like', $like)
                ->orWhereHas('person', fn ($person) => $person->where('name', 'like', $like)))
            ->latest('leads.id')
            ->limit(10)
            ->get(['leads.id', 'leads.title', 'leads.person_id']);

        return response()->json([
            'data' => $leads->map(fn (Lead $lead) => [
                'id'     => $lead->id,
                'title'  => $lead->title,
                'person' => $lead->person?->name,
            ]),
        ]);
    }

    public function link(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['lead_id' => ['nullable', 'integer', 'exists:leads,id']]);

        $conversation = Conversation::query()->visible()->whereNotNull('session_id')->findOrFail($id);

        if (! empty($data['lead_id'])) {
            $userIds = bouncer()->getAuthorizedUserIds();

            abort_if(
                $userIds && ! Lead::query()->whereKey($data['lead_id'])->whereIn('user_id', $userIds)->exists(),
                403,
            );
        }

        $conversation->lead_id = $data['lead_id'] ?? null;
        $conversation->save();

        $lead = $conversation->lead_id ? Lead::query()->find($conversation->lead_id, ['id', 'title']) : null;

        return response()->json([
            'lead' => $lead ? [
                'id'    => $lead->id,
                'title' => $lead->title,
                'url'   => route('admin.leads.view', $lead->id),
            ] : null,
        ]);
    }

    /**
     * Pipelines (with their open stages, by sort_order) the "Criar lead" form offers, the
     * default one marked, so the operator can choose where the lead goes.
     */
    public function options(int $id, PipelineRepository $pipelines): JsonResponse
    {
        abort_unless(bouncer()->hasPermission('leads.create'), 403);

        Conversation::query()->visible()->whereNotNull('session_id')->findOrFail($id);

        $allowed = bouncer()->getAllowedPipelineIds();
        $defaultId = $pipelines->getDefaultPipeline()?->id;

        $list = Pipeline::query()
            ->with(['stages' => fn ($query) => $query->where(fn ($open) => $open->whereNull('code')->orWhereNotIn('code', ['won', 'lost']))->orderBy('sort_order')])
            ->when($allowed !== null, fn ($query) => $query->whereIn('id', $allowed))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->filter(fn (Pipeline $pipeline) => $pipeline->stages->isNotEmpty())
            ->map(fn (Pipeline $pipeline) => [
                'id'         => (int) $pipeline->id,
                'name'       => $pipeline->name,
                'is_default' => (int) $pipeline->id === (int) $defaultId,
                'stages'     => $pipeline->stages->map(fn (Stage $stage) => ['id' => (int) $stage->id, 'name' => $stage->name])->values()->all(),
            ])
            ->values();

        return response()->json([
            'pipelines'           => $list,
            'default_pipeline_id' => $list->firstWhere('is_default', true)['id'] ?? $list->first()['id'] ?? null,
        ]);
    }

    /**
     * Opens a lead for the contact of this conversation, with the contact as the person: in
     * the chosen pipeline and stage (pipeline_id / stage_id), else in the default pipeline at
     * its first stage. When the contact already has a lead (or a person without one) that one
     * is reused instead of creating a duplicate.
     */
    public function create(Request $request, int $id, ConversationLead $service): JsonResponse
    {
        abort_unless(bouncer()->hasPermission('leads.create'), 403);

        $data = $request->validate([
            'pipeline_id' => ['nullable', 'integer', 'required_with:stage_id', 'exists:lead_pipelines,id'],
            'stage_id'    => ['nullable', 'integer', 'exists:lead_pipeline_stages,id'],
        ]);

        $conversation = Conversation::query()->visible()->whereNotNull('session_id')->findOrFail($id);

        abort_if($conversation->lead_id !== null, 422, trans('whatsapp::app.inbox.already_has_lead'));

        [$pipeline, $stage] = $this->chosenPlacement($data);

        $userIds = bouncer()->getAuthorizedUserIds();

        // A lead for this number already exists: link it rather than opening a second one.
        $existingId = $service->existingLeadId($conversation);

        if ($existingId !== null) {
            $visible = ! $userIds || Lead::query()->whereKey($existingId)->whereIn('user_id', $userIds)->exists();

            abort_unless($visible, 409, trans('whatsapp::app.inbox.lead_exists_hidden'));

            $conversation->lead_id = $existingId;
            $conversation->save();

            return $this->leadResponse($existingId, false);
        }

        abort_if(($pipeline === null && $service->defaultPlacement() === null) || ! Type::query()->exists(), 422, trans('whatsapp::app.inbox.no_pipeline'));

        $user = auth()->guard('user')->user();

        $lead = $service->open($conversation, [
            'title'           => 'WhatsApp - '.ConversationLead::label($conversation, trans('whatsapp::app.inbox.unknown')),
            'description'     => trans('whatsapp::app.inbox.lead_created_from_whatsapp'),
            'source'          => 'WhatsApp',
            'user_id'         => $conversation->assigned_user_id ?? $user->id,
            'person_owner_id' => $user->id,
            'person_user_ids' => $userIds,
            'pipeline'        => $pipeline,
            'stage'           => $stage,
        ]);

        return $this->leadResponse($lead->id, true);
    }

    /**
     * The pipeline and stage picked in the form: the stage must be an open stage of that
     * pipeline (its first one when none is given), and the pipeline one the user may use.
     *
     * @return array{0: ?Pipeline, 1: ?Stage}  [null, null] = the default placement.
     */
    private function chosenPlacement(array $data): array
    {
        if (empty($data['pipeline_id'])) {
            return [null, null];
        }

        $allowed = bouncer()->getAllowedPipelineIds();

        abort_if($allowed !== null && ! in_array((int) $data['pipeline_id'], $allowed, true), 403);

        $pipeline = Pipeline::query()->findOrFail((int) $data['pipeline_id']);

        $stage = $pipeline->stages()
            ->where(fn ($open) => $open->whereNull('code')->orWhereNotIn('code', ['won', 'lost']))
            ->when(! empty($data['stage_id']), fn ($query) => $query->whereKey((int) $data['stage_id']))
            ->orderBy('sort_order')
            ->first();

        abort_if($stage === null, 422, trans('whatsapp::app.inbox.stage_invalid'));

        return [$pipeline, $stage];
    }

    private function leadResponse(int $leadId, bool $created): JsonResponse
    {
        $lead = Lead::query()->with('stage:id,name')->find($leadId, ['id', 'title', 'lead_pipeline_stage_id']);

        return response()->json([
            'created' => $created,
            'lead'    => [
                'id'    => $lead->id,
                'title' => $lead->title,
                'url'   => route('admin.leads.view', $lead->id),
                'stage' => $lead->stage?->name,
            ],
        ], $created ? 201 : 200);
    }
}
