<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Support\LeadCustomFieldFilters;

uses(DatabaseTransactions::class);

/**
 * Custom text fields are filtered by picking one of their existing values, unless they have too many.
 * Needs a migrated and seeded database.
 */
function customTextField(string $name, array $values): object
{
    $now = now();
    $id = DB::table('attributes')->insertGetId([
        'entity_type' => 'leads', 'is_user_defined' => 1, 'is_required' => 0, 'is_unique' => 0, 'quick_add' => 0,
        'sort_order' => 99, 'created_at' => $now, 'updated_at' => $now, 'type' => 'text',
        'code' => 'zz_'.uniqid(), 'name' => $name,
    ]);

    $pipeline = DB::table('lead_pipelines')->where('is_default', 1)->value('id');
    $stage = DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipeline)->orderBy('sort_order')->value('id');

    foreach ($values as $value) {
        $lead = DB::table('leads')->insertGetId(['title' => 'ZZ '.uniqid(), 'status' => 1, 'lead_value' => 0, 'lead_pipeline_id' => $pipeline, 'lead_pipeline_stage_id' => $stage, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('attribute_values')->insert(['entity_type' => 'leads', 'entity_id' => $lead, 'attribute_id' => $id, 'text_value' => $value]);
    }

    return DB::table('attributes')->where('id', $id)->first(['id', 'code', 'name', 'type']);
}

beforeEach(function () {
    (new ReflectionProperty(LeadCustomFieldFilters::class, 'distinct'))->setValue(null, null);
});

it('lists the existing values of a text field, most used first, with their count', function () {
    $field = customTextField('Segmento', ['logistica', 'varejo', 'logistica', '', 'saude']);

    $values = LeadCustomFieldFilters::values(LeadCustomFieldFilters::index($field));

    expect(LeadCustomFieldFilters::usesOptions($field))->toBeTrue()
        ->and(array_column($values, 'value'))->toBe(['logistica', 'saude', 'varejo'])
        ->and($values[0]['label'])->toBe('logistica (2)')
        ->and(LeadCustomFieldFilters::column($field)['filterable_type'])->toBe('dropdown');
});

it('keeps the free text box for a field with too many distinct values', function () {
    $field = customTextField('Livre', array_map(fn ($i) => "valor $i", range(1, LeadCustomFieldFilters::OPTIONS_LIMIT + 1)));

    expect(LeadCustomFieldFilters::usesOptions($field))->toBeFalse()
        ->and(LeadCustomFieldFilters::values(LeadCustomFieldFilters::index($field)))->toBe([])
        ->and(LeadCustomFieldFilters::column($field)['filterable_type'])->toBeNull();
});

it('matches exactly when the value is picked from the list', function () {
    $field = customTextField('Segmento', ['logistica', 'logistica online', 'varejo']);
    $index = LeadCustomFieldFilters::index($field);

    $query = DB::table('leads');
    LeadCustomFieldFilters::apply($query, json_encode([['index' => $index, 'value' => ['logistica']]]));

    // "logistica online" contains "logistica" but is another value.
    expect($query->count())->toBe(1);
});
