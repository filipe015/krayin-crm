<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Goals\Models\GoalChange;
use Webkul\Goals\Models\GoalSetting;
use Webkul\Goals\Support\GoalSettingsService;

uses(DatabaseTransactions::class);

/**
 * How the funnel steps are recognised and the default weekdays. Needs a migrated and seeded database.
 */
beforeEach(fn () => GoalSetting::query()->delete());

it('starts with an appointment for "scheduled", nothing for "attended" and the weekdays Monday to Friday', function () {
    $settings = GoalSettingsService::get();

    expect($settings['default_weekdays'])->toBe([1, 2, 3, 4, 5])
        ->and($settings['step_rules']['scheduled'])->toBe([['type' => 'appointment']])
        ->and($settings['step_rules']['attended'])->toBe([]);
});

it('takes the "1º contato" stage of the funnel as the qualified step, when there is one', function () {
    $pipeline = DB::table('lead_pipelines')->where('is_default', 1)->value('id');
    $stage = DB::table('lead_pipeline_stages')->insertGetId(['code' => 'zz-primeiro', 'name' => '1º Contato', 'probability' => 10, 'sort_order' => 99, 'lead_pipeline_id' => $pipeline]);

    expect(GoalSettingsService::get()['step_rules']['qualified'])->toEqual([['type' => 'stage', 'pipeline_id' => (int) $pipeline, 'stage_ids' => [$stage]]]);
});

it('leaves the qualified step empty when the funnel has no such stage', function () {
    expect(GoalSettingsService::get()['step_rules']['qualified'])->toBe([]);
});

it('saves the rules and the weekdays, and writes what changed to the history', function () {
    $userId = (int) getDefaultAdmin()->id;

    GoalSettingsService::get();

    GoalSettingsService::save([
        'qualified' => [['type' => 'stage', 'pipeline_id' => 1, 'stage_ids' => [3, 3, 4]]],
        'scheduled' => [['type' => 'appointment']],
        'attended'  => [['type' => 'tag', 'tag_ids' => [9]], ['type' => 'stage', 'pipeline_id' => 1, 'stage_ids' => [7]]],
    ], [6, 1, 2, 3, 4, 5, 1], $userId);

    $saved = GoalSettingsService::get();

    // JSON comes back from the database with its keys in another order: compare the content
    expect($saved['step_rules']['qualified'])->toEqual([['type' => 'stage', 'pipeline_id' => 1, 'stage_ids' => [3, 4]]])
        ->and($saved['step_rules']['attended'])->toHaveCount(2)
        ->and($saved['default_weekdays'])->toBe([1, 2, 3, 4, 5, 6]);

    $changes = GoalChange::query()->where('subject', 'settings')->get()->keyBy('field');

    // "scheduled" did not change: it is not in the history
    expect($changes->keys()->all())->toContain('step_rules.qualified', 'step_rules.attended', 'default_weekdays')
        ->and($changes)->not->toHaveKey('step_rules.scheduled')
        ->and($changes['default_weekdays']->new_value)->toBe('[1,2,3,4,5,6]')
        ->and($changes['default_weekdays']->user_id)->toBe($userId);
});

it('refuses rules that are not valid', function (array $rules, array $weekdays) {
    GoalSettingsService::get();

    expect(fn () => GoalSettingsService::save($rules, $weekdays))->toThrow(InvalidArgumentException::class);
})->with([
    'unknown type'      => [['qualified' => [['type' => 'magic']]], [1, 2, 3]],
    'stage without ids' => [['qualified' => [['type' => 'stage', 'pipeline_id' => 1, 'stage_ids' => []]]], [1, 2, 3]],
    'stage without funnel' => [['qualified' => [['type' => 'stage', 'stage_ids' => [1]]]], [1, 2, 3]],
    'tag without ids'   => [['attended' => [['type' => 'tag', 'tag_ids' => []]]], [1, 2, 3]],
    'no weekdays'       => [[], []],
    'weekday 8'         => [[], [1, 8]],
]);
