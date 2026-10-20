<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Event;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Tag\Models\Tag;
use Webkul\WhatsApp\Models\Conversation;

/**
 * Lets the operator see and move the lead of a conversation through the Krayin pipeline
 * (stage and tags) without leaving the Inbox.
 */
class LeadFunnelController extends Controller
{
    public function __construct(protected LeadRepository $leads) {}

    public function show(int $id): JsonResponse
    {
        $lead = $this->leadOf($id);

        return response()->json(['data' => $lead ? $this->payload($lead) : null]);
    }

    public function stage(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'stage_id'    => ['required', 'integer'],
            'lost_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $lead = $this->leadOf($id);

        abort_if($lead === null, 404);

        // Only stages of the lead's own pipeline are valid targets.
        $stage = $lead->pipeline->stages()->whereKey($data['stage_id'])->firstOrFail();

        Event::dispatch('lead.update.before', $lead->id);

        $payload = ['entity_type' => 'leads', 'lead_pipeline_stage_id' => $stage->id];

        if ($stage->code === 'lost' && ! empty($data['lost_reason'])) {
            $payload['lost_reason'] = $data['lost_reason'];
        }

        $updated = $this->leads->update($payload, $lead->id, ['lead_pipeline_stage_id']);

        Event::dispatch('lead.update.after', $updated);

        return response()->json(['data' => $this->payload($this->leadOf($id))]);
    }

    public function attachTag(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['tag_id' => ['required', 'integer', 'exists:tags,id']]);

        $lead = $this->leadOf($id);

        abort_if($lead === null, 404);

        $lead->tags()->syncWithoutDetaching([$data['tag_id']]);

        return response()->json(['data' => $this->payload($this->leadOf($id))]);
    }

    public function detachTag(int $id, int $tagId): JsonResponse
    {
        $lead = $this->leadOf($id);

        abort_if($lead === null, 404);

        $lead->tags()->detach($tagId);

        return response()->json(['data' => $this->payload($this->leadOf($id))]);
    }

    /**
     * The lead linked to the conversation, if the user is allowed to see it.
     */
    private function leadOf(int $conversationId): ?Lead
    {
        $conversation = Conversation::query()->visible()->whereNotNull('session_id')->findOrFail($conversationId);

        if ($conversation->lead_id === null) {
            return null;
        }

        $userIds = bouncer()->getAuthorizedUserIds();

        return Lead::query()
            ->when($userIds, fn ($query) => $query->whereIn('leads.user_id', $userIds))
            ->with(['pipeline.stages', 'stage', 'tags'])
            ->find($conversation->lead_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Lead $lead): array
    {
        $attached = $lead->tags->pluck('id')->all();

        return [
            'lead'      => ['id' => $lead->id, 'title' => $lead->title],
            'pipeline'  => $lead->pipeline?->name,
            'stage_id'  => $lead->lead_pipeline_stage_id,
            'stage'     => $lead->stage ? ['id' => $lead->stage->id, 'name' => $lead->stage->name, 'code' => $lead->stage->code] : null,
            'stages'    => $lead->pipeline
                ? $lead->pipeline->stages->sortBy('sort_order')->values()->map(fn ($stage) => ['id' => $stage->id, 'name' => $stage->name, 'code' => $stage->code])
                : [],
            'tags'      => $lead->tags->map(fn ($tag) => ['id' => $tag->id, 'name' => $tag->name, 'color' => $tag->color])->values(),
            'available_tags' => Tag::query()->whereNotIn('id', $attached)->orderBy('name')->limit(100)->get(['id', 'name', 'color']),
        ];
    }
}
