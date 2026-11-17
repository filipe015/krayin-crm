<?php

namespace Webkul\Automations\Support;

use Illuminate\Support\Facades\DB;
use Webkul\Lead\Models\Lead;

/**
 * The "Se…" part: every condition must hold (AND), checked against the lead as it is when the rule
 * runs (not when the event happened).
 *
 *  - pipeline {pipeline_id}            - stage {stage_id}          - source {source_id}
 *  - tag {tag_id} (the lead has it)    - owner {user_id}           - field {attribute_code, value}
 */
final class ConditionEvaluator
{
    /** Lead columns a "field" condition may read directly. */
    public const LEAD_COLUMNS = ['title', 'description', 'lead_value', 'expected_close_date', 'lead_source_id', 'lead_type_id', 'lost_reason', 'person_id'];

    /**
     * @param  list<array<string, mixed>>  $conditions
     * @return array{0: bool, 1: list<array<string, mixed>>} passes, and one check per condition
     */
    public static function evaluate(array $conditions, Lead $lead): array
    {
        $checks = [];
        $passes = true;

        foreach ($conditions as $condition) {
            $type = (string) ($condition['type'] ?? '');
            $ok = self::check($type, (array) $condition, $lead);
            $passes = $passes && $ok;

            $checks[] = [
                'action' => 'condition:'.$type,
                'status' => $ok ? 'ok' : 'skipped',
                'detail' => trans('automations::app.engine.condition_'.($ok ? 'met' : 'not_met'), ['condition' => trans('automations::app.conditions.'.$type)]),
            ];
        }

        return [$passes, $checks];
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    public static function check(string $type, array $condition, Lead $lead): bool
    {
        return match ($type) {
            'pipeline' => (int) $lead->lead_pipeline_id === (int) ($condition['pipeline_id'] ?? 0),
            'stage' => (int) $lead->lead_pipeline_stage_id === (int) ($condition['stage_id'] ?? 0),
            'source' => (int) $lead->lead_source_id === (int) ($condition['source_id'] ?? 0),
            'owner' => (int) $lead->user_id === (int) ($condition['user_id'] ?? 0),
            'tag' => DB::table('lead_tags')->where('lead_id', $lead->id)->where('tag_id', (int) ($condition['tag_id'] ?? 0))->exists(),
            'field' => self::fieldEquals($lead, (string) ($condition['attribute_code'] ?? ''), (string) ($condition['value'] ?? '')),
            default => false,
        };
    }

    /**
     * Compares as text, ignoring case and surrounding spaces. For option fields (select, lookup…)
     * both the stored id and the option's label count, so "Sim" and "12" can both be written.
     * An empty value means "the field is empty".
     */
    public static function fieldEquals(Lead $lead, string $code, string $expected): bool
    {
        if ($code === '') {
            return false;
        }

        $expected = self::normalize($expected);

        $candidates = in_array($code, self::LEAD_COLUMNS, true)
            ? [$lead->getRawOriginal($code)]
            : self::attributeValues($lead->id, $code);

        if ($expected === '') {
            return collect($candidates)->every(fn ($value) => self::normalize((string) $value) === '');
        }

        foreach ($candidates as $value) {
            $value = self::normalize((string) $value);

            if ($value === $expected || (is_numeric($value) && is_numeric($expected) && (float) $value === (float) $expected)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<mixed>
     */
    private static function attributeValues(int $leadId, string $code): array
    {
        $attribute = DB::table('attributes')->where('entity_type', 'leads')->where('code', $code)->first(['id', 'type']);

        if (! $attribute) {
            return [];
        }

        $row = DB::table('attribute_values')->where('entity_type', 'leads')->where('entity_id', $leadId)->where('attribute_id', $attribute->id)->first();

        if (! $row) {
            return [null];
        }

        $values = [];

        foreach (['text_value', 'boolean_value', 'integer_value', 'float_value', 'date_value', 'datetime_value'] as $column) {
            if ($row->{$column} !== null) {
                $values[] = $row->{$column};
            }
        }

        if ($row->json_value !== null) {
            foreach ((array) json_decode((string) $row->json_value, true) as $item) {
                $values[] = is_array($item) ? ($item['value'] ?? null) : $item;
            }
        }

        // Option labels for option fields (the value holds the option id, or several ids separated by commas).
        $ids = collect($values)->flatMap(fn ($value) => explode(',', (string) $value))->map(fn ($id) => trim($id))->filter(fn ($id) => ctype_digit($id))->all();

        if ($ids !== [] && in_array($attribute->type, ['select', 'multiselect', 'checkbox', 'radio'], true)) {
            $values = array_merge($values, DB::table('attribute_options')->where('attribute_id', $attribute->id)->whereIn('id', $ids)->pluck('name')->all());
        }

        return $values === [] ? [null] : $values;
    }

    private static function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
