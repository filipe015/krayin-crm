<?php

namespace Webkul\WhatsApp\Api\Support;

use Illuminate\Support\Facades\DB;
use Webkul\Lead\Models\Lead;

/**
 * The public shape of a lead. Kept here so the REST API and the webhooks describe a lead the same way.
 */
final class LeadPresenter
{
    public static function make(Lead $lead): array
    {
        $lead->loadMissing(['person', 'stage', 'pipeline', 'user', 'source', 'type', 'tags']);

        $person = $lead->person;

        $stageCode = $lead->stage?->code;

        return [
            'id'                  => $lead->id,
            'title'               => $lead->title,
            'description'         => $lead->description,
            'value'               => (float) $lead->lead_value,
            'status'              => match ($stageCode) {
                'won'   => 'won',
                'lost'  => 'lost',
                default => 'open',
            },
            'lost_reason'         => $lead->lost_reason,
            'pipeline'            => $lead->pipeline ? ['id' => $lead->pipeline->id, 'name' => $lead->pipeline->name] : null,
            'stage'               => $lead->stage ? ['id' => $lead->stage->id, 'name' => $lead->stage->name, 'code' => $lead->stage->code] : null,
            'source'              => $lead->source ? ['id' => $lead->source->id, 'name' => $lead->source->name] : null,
            'type'                => $lead->type ? ['id' => $lead->type->id, 'name' => $lead->type->name] : null,
            'owner'               => $lead->user ? ['id' => $lead->user->id, 'name' => $lead->user->name] : null,
            'person'              => $person ? [
                'id'             => $person->id,
                'name'           => $person->name,
                'emails'         => array_values(array_filter(array_map(fn ($item) => $item['value'] ?? null, (array) $person->emails))),
                'phones'         => array_values(array_filter(array_map(fn ($item) => $item['value'] ?? null, (array) $person->contact_numbers))),
            ] : null,
            'tags'                => $lead->tags->pluck('name')->values()->all(),
            'created_at'          => $lead->created_at?->toIso8601String(),
            'updated_at'          => $lead->updated_at?->toIso8601String(),
            'stage_entered_at'    => $lead->stage_entered_at?->toIso8601String(),
            'closed_at'           => $lead->getRawOriginal('closed_at') ? \Illuminate\Support\Carbon::parse($lead->getRawOriginal('closed_at'))->toIso8601String() : null,
            'expected_close_date' => $lead->getRawOriginal('expected_close_date'),
            'custom'              => self::custom($lead),
        ];
    }

    /**
     * Values of the user-defined fields (Campos Personalizados) of the lead, by field code.
     */
    private static function custom(Lead $lead): array
    {
        $rows = DB::table('attribute_values')
            ->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')
            ->where('attribute_values.entity_type', 'leads')
            ->where('attribute_values.entity_id', $lead->id)
            ->where('attributes.is_user_defined', 1)
            ->get(['attributes.code', 'attributes.type', 'attribute_values.text_value', 'attribute_values.boolean_value',
                'attribute_values.integer_value', 'attribute_values.float_value', 'attribute_values.date_value',
                'attribute_values.datetime_value', 'attribute_values.json_value']);

        $custom = [];

        foreach ($rows as $row) {
            $custom[$row->code] = $row->text_value
                ?? $row->integer_value
                ?? $row->float_value
                ?? $row->date_value
                ?? $row->datetime_value
                ?? $row->boolean_value
                ?? (isset($row->json_value) ? json_decode($row->json_value, true) : null);
        }

        return $custom;
    }
}
