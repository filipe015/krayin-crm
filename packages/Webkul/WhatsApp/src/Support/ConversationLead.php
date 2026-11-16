<?php

namespace Webkul\WhatsApp\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Webkul\Contact\Models\Person;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Models\Pipeline;
use Webkul\Lead\Models\Source;
use Webkul\Lead\Models\Stage;
use Webkul\Lead\Models\Type;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Lead\Repositories\PipelineRepository;
use Webkul\Tag\Models\Tag;
use Webkul\User\Models\User;
use Webkul\WhatsApp\Models\Conversation;

/**
 * Opens the lead of a WhatsApp conversation, shared by "Criar lead" in the Inbox and the
 * automatic lead of incoming messages: the person is found by phone (or created), a lead
 * that already exists for the number is linked instead of duplicated, value 0, the first
 * lead type, and the lead.create.before/after events like the rest of Krayin.
 */
class ConversationLead
{
    public function __construct(
        protected LeadRepository $leads,
        protected PipelineRepository $pipelines,
        protected LeadPhoneFinder $finder,
    ) {}

    public static function phone(Conversation $conversation): string
    {
        return (string) preg_replace('/\D/', '', (string) $conversation->contact_phone);
    }

    /**
     * Contact name, else "+<phone>", else the given fallback.
     */
    public static function label(Conversation $conversation, string $fallback): string
    {
        $name = trim((string) $conversation->contact_name);
        $phone = self::phone($conversation);

        return $name !== '' ? $name : ($phone !== '' ? '+'.$phone : $fallback);
    }

    /**
     * Newest lead whose person has this conversation's number.
     */
    public function existingLeadId(Conversation $conversation): ?int
    {
        $phone = self::phone($conversation);

        return $phone !== '' ? $this->finder->find($phone) : null;
    }

    /**
     * @return array{0: Pipeline, 1: Stage}|null  The default pipeline and its first stage.
     */
    public function defaultPlacement(): ?array
    {
        $pipeline = $this->pipelines->getDefaultPipeline();
        $stage = $pipeline?->stages()->orderBy('sort_order')->first();

        return $pipeline !== null && $stage !== null ? [$pipeline, $stage] : null;
    }

    /**
     * Creates the lead and links the conversation to it.
     *
     * @param  array{
     *     title: string,
     *     description?: ?string,
     *     source: string,
     *     tags?: list<string>,
     *     user_id?: ?int,
     *     pipeline?: ?Pipeline,
     *     stage?: ?Stage,
     *     person_user_ids?: ?array,
     *     person_owner_id?: ?int,
     * }  $options  person_user_ids: owners whose persons may be reused (null = any person);
     *              person_owner_id: owner of a new person (default: the lead's owner).
     *
     * @throws RuntimeException  When there is no pipeline, stage or lead type to use.
     */
    public function open(Conversation $conversation, array $options): Lead
    {
        $pipeline = $options['pipeline'] ?? null;
        $stage = $options['stage'] ?? null;

        if ($pipeline === null || $stage === null) {
            [$pipeline, $stage] = $this->defaultPlacement() ?? [null, null];
        }

        $type = Type::query()->orderBy('id')->first();

        if ($pipeline === null || $stage === null || $type === null) {
            throw new RuntimeException('There is no pipeline, stage or lead type to open the lead in.');
        }

        $phone = self::phone($conversation);
        $userId = $options['user_id'] ?? null;
        $source = Source::query()->firstOrCreate(['name' => $options['source']]);
        $person = $phone !== '' ? $this->personWithPhone($phone, $options['person_user_ids'] ?? null) : null;
        $label = self::label($conversation, trans('whatsapp::app.inbox.unknown'));

        $data = [
            'entity_type'            => 'leads',
            'title'                  => mb_substr($options['title'], 0, 250),
            'description'            => $options['description'] ?? null,
            'lead_value'             => 0,
            'status'                 => 1,
            'lead_source_id'         => $source->id,
            'lead_type_id'           => $type->id,
            'user_id'                => $userId,
            'lead_pipeline_id'       => $pipeline->id,
            'lead_pipeline_stage_id' => $stage->id,
            'person'                 => $person !== null
                ? ['id' => $person->id]
                : [
                    'name'            => $label,
                    'contact_numbers' => $phone !== '' ? [['value' => $phone, 'label' => 'work']] : [],
                    'user_id'         => $options['person_owner_id'] ?? $userId,
                ],
        ];

        $tagOwner = empty($options['tags']) ? null : $this->tagOwner($userId);

        $tagIds = collect($options['tags'] ?? [])
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique()
            ->map(fn (string $name) => Tag::query()->firstOrCreate(['name' => $name], ['user_id' => $tagOwner])->id)
            ->values()
            ->all();

        Event::dispatch('lead.create.before');

        $lead = DB::transaction(function () use ($data, $conversation, $tagIds) {
            $lead = $this->leads->create($data);

            if ($tagIds !== []) {
                $lead->tags()->syncWithoutDetaching($tagIds);
            }

            $conversation->lead_id = $lead->id;
            $conversation->save();

            return $lead;
        });

        Event::dispatch('lead.create.after', $lead);

        return $lead;
    }

    /**
     * Tags need an owner: the lead's, else the first user (an automatic lead may have none).
     */
    private function tagOwner(?int $userId): ?int
    {
        return $userId ?? User::query()->orderBy('id')->value('id');
    }

    /**
     * A person that already has this number (restricted to the given owners), so a new lead
     * for a known contact hangs from the same person.
     *
     * @param  list<int>|null  $userIds
     */
    public function personWithPhone(string $phone, ?array $userIds): ?Person
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
