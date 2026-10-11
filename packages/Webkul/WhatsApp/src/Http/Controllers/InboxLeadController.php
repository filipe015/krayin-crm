<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Contact\Models\Person;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Models\Source;
use Webkul\Lead\Models\Type;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Lead\Repositories\PipelineRepository;
use Webkul\WhatsApp\Models\Conversation;
use Webkul\WhatsApp\Support\LeadPhoneFinder;
use Webkul\WhatsApp\Support\PhoneNumberMatcher;

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

    /**
     * Opens a lead for the contact of this conversation: in the default pipeline, at its
     * first stage, with the contact as the person. When the contact already has a lead (or
     * a person without one) that one is reused instead of creating a duplicate.
     */
    public function create(int $id, LeadRepository $leads, PipelineRepository $pipelines, LeadPhoneFinder $finder): JsonResponse
    {
        abort_unless(bouncer()->hasPermission('leads.create'), 403);

        $conversation = Conversation::query()->whereNotNull('session_id')->findOrFail($id);

        abort_if($conversation->lead_id !== null, 422, trans('whatsapp::app.inbox.already_has_lead'));

        $phone = preg_replace('/\D/', '', (string) $conversation->contact_phone);
        $userIds = bouncer()->getAuthorizedUserIds();

        // A lead for this number already exists: link it rather than opening a second one.
        $existingId = $phone !== '' ? $finder->find($phone) : null;

        if ($existingId !== null) {
            $visible = ! $userIds || Lead::query()->whereKey($existingId)->whereIn('user_id', $userIds)->exists();

            abort_unless($visible, 409, trans('whatsapp::app.inbox.lead_exists_hidden'));

            $conversation->lead_id = $existingId;
            $conversation->save();

            return $this->leadResponse($existingId, false);
        }

        $pipeline = $pipelines->getDefaultPipeline();
        $stage = $pipeline?->stages()->orderBy('sort_order')->first();
        $type = Type::query()->orderBy('id')->first();

        abort_if($pipeline === null || $stage === null || $type === null, 422, trans('whatsapp::app.inbox.no_pipeline'));

        $source = Source::query()->firstOrCreate(['name' => 'WhatsApp']);
        $name = trim((string) $conversation->contact_name);
        $label = $name !== '' ? $name : ($phone !== '' ? '+'.$phone : trans('whatsapp::app.inbox.unknown'));
        $user = auth()->guard('user')->user();

        $person = $phone !== '' ? $this->personWithPhone($phone, $userIds) : null;

        $data = [
            'entity_type'            => 'leads',
            'title'                  => 'WhatsApp - '.$label,
            'description'            => trans('whatsapp::app.inbox.lead_created_from_whatsapp'),
            'lead_value'             => 0,
            'status'                 => 1,
            'lead_source_id'         => $source->id,
            'lead_type_id'           => $type->id,
            'user_id'                => $conversation->assigned_user_id ?? $user->id,
            'lead_pipeline_id'       => $pipeline->id,
            'lead_pipeline_stage_id' => $stage->id,
            'person'                 => $person !== null
                ? ['id' => $person->id]
                : [
                    'name'            => $label,
                    'contact_numbers' => $phone !== '' ? [['value' => $phone, 'label' => 'work']] : [],
                    'user_id'         => $user->id,
                ],
        ];

        Event::dispatch('lead.create.before');

        $lead = DB::transaction(function () use ($leads, $data, $conversation) {
            $lead = $leads->create($data);

            $conversation->lead_id = $lead->id;
            $conversation->save();

            return $lead;
        });

        Event::dispatch('lead.create.after', $lead);

        return $this->leadResponse($lead->id, true);
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

    /**
     * A person (visible to the user) that already has this number, so a new lead for a
     * known contact hangs from the same person.
     *
     * @param  list<int>|null  $userIds
     */
    private function personWithPhone(string $phone, ?array $userIds): ?Person
    {
        return Person::query()
            ->whereNotNull('contact_numbers')
            ->when($userIds, fn ($query) => $query->whereIn('user_id', $userIds))
            ->latest('id')
            ->cursor()
            ->first(function (Person $person) use ($phone) {
                $numbers = is_array($person->contact_numbers) ? $person->contact_numbers : json_decode((string) $person->contact_numbers, true);

                foreach ((array) $numbers as $number) {
                    $value = is_array($number) ? ($number['value'] ?? null) : $number;

                    if ($value !== null && PhoneNumberMatcher::matches((string) $value, $phone)) {
                        return true;
                    }
                }

                return false;
            });
    }
}
