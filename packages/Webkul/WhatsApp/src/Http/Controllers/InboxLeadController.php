<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webkul\Lead\Models\Lead;
use Webkul\WhatsApp\Models\Conversation;

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

        $conversation = Conversation::query()->whereNotNull('session_id')->findOrFail($id);

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
}
