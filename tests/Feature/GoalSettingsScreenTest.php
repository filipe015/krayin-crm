<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Goals\Models\GoalChange;
use Webkul\Goals\Models\GoalPlan;
use Webkul\Goals\Support\GoalPlanService;

uses(DatabaseTransactions::class);

/**
 * Configurações → Metas: the routes behind the screen. Needs a migrated and seeded database.
 */
function goalsRoute(string $name): string
{
    return route('admin.goals.settings.'.$name);
}

function goalsPlan(array $override = []): array
{
    return $override + ['month' => '2026-10', 'funnel' => '', 'revenue_target' => 50000, 'ticket' => 2500, 'qualification_rate' => 50, 'scheduling_rate' => 50, 'attendance_rate' => 80, 'closing_rate' => 40, 'cpl_target' => 30, 'budget' => 8000];
}

it('opens the screen', function () {
    test()->actingAs(getDefaultAdmin())->get(goalsRoute('index'))->assertOk()->assertSee('v-goals-settings', false);
});

it('previews what the funnel needs without saving', function () {
    $response = test()->actingAs(getDefaultAdmin())->postJson(goalsRoute('preview'), goalsPlan())->assertOk();

    expect($response->json('leads_target'))->toBe(252)
        ->and($response->json('sales_target'))->toBe(20)
        ->and($response->json('budget_needed'))->toBe(7560)
        ->and(GoalPlan::query()->count())->toBe(0);
});

it('previews with a step fixed by hand', function () {
    $response = test()->actingAs(getDefaultAdmin())->postJson(goalsRoute('preview'), goalsPlan(['scheduled_target' => 100, 'fixed' => ['scheduled']]))->assertOk();

    expect($response->json('scheduled_target'))->toBe(100)->and($response->json('leads_target'))->toBe(400);
});

it('saves a plan and returns the whole month with its weeks and days', function () {
    $response = test()->actingAs(getDefaultAdmin())->postJson(goalsRoute('plan'), goalsPlan())->assertOk();

    $plan = $response->json('plans.0');

    expect($response->json('month'))->toBe('2026-10')
        ->and($plan['funnel'])->toBe('')
        ->and($plan['values']['leads_target'])->toBe(252)
        ->and($plan['weeks'])->toHaveCount(5)
        ->and($plan['days'])->toHaveCount(31)
        ->and(array_sum(array_column(array_column($plan['weeks'], 'targets'), 'leads_target')))->toBe(252)
        ->and(array_sum(array_column(array_column($plan['days'], 'targets'), 'revenue_target')))->toBe(50000)
        ->and($response->json('working_days'))->toHaveCount(22);
});

it('refuses numbers that are not whole, with messages in Portuguese', function () {
    app()->setLocale('pt_BR');

    $response = test()->actingAs(getDefaultAdmin())->postJson(goalsRoute('plan'), goalsPlan(['revenue_target' => '1500.50', 'closing_rate' => 150]))->assertStatus(422);

    expect($response->json('errors.revenue_target.0'))->toContain('meta de faturamento')
        ->and($response->json('errors.closing_rate.0'))->toContain('taxa comparecimento')
        ->and(GoalPlan::query()->count())->toBe(0);
});

it('removes a plan and copies a month', function () {
    $admin = getDefaultAdmin();

    test()->actingAs($admin)->postJson(goalsRoute('plan'), goalsPlan())->assertOk();
    test()->actingAs($admin)->postJson(goalsRoute('plan'), goalsPlan(['funnel' => 'Lead - Online', 'revenue_target' => 20000]))->assertOk();

    $copy = test()->actingAs($admin)->postJson(goalsRoute('copy'), ['from' => '2026-10', 'to' => '2026-11'])->assertOk();

    expect($copy->json('copied'))->toBe(2)->and($copy->json('plans'))->toHaveCount(2);

    $removed = test()->actingAs($admin)->deleteJson(goalsRoute('plan.delete'), ['month' => '2026-11', 'funnel' => 'Lead - Online'])->assertOk();

    expect($removed->json('plans'))->toHaveCount(1);

    test()->actingAs($admin)->postJson(goalsRoute('copy'), ['from' => '2026-10', 'to' => '2026-10'])->assertStatus(422);
});

it('saves the working days of a month', function () {
    $admin = getDefaultAdmin();
    $days = array_slice(GoalPlanService::workingDays('2026-10'), 0, 15);

    $response = test()->actingAs($admin)->postJson(goalsRoute('working_days'), ['month' => '2026-10', 'days' => $days])->assertOk();

    expect($response->json('working_days'))->toBe($days);

    test()->actingAs($admin)->postJson(goalsRoute('working_days'), ['month' => '2026-10', 'days' => ['2026-11-02']])->assertStatus(422);
});

it('saves the step rules, ignoring what the type of a rule does not use', function () {
    $admin = getDefaultAdmin();

    $response = test()->actingAs($admin)->postJson(goalsRoute('save'), [
        'weekdays'   => [1, 2, 3, 4, 5, 6],
        'step_rules' => [
            'qualified' => [['type' => 'stage', 'pipeline_id' => 1, 'stage_ids' => [3]]],
            'scheduled' => [['type' => 'appointment', 'pipeline_id' => 1, 'stage_ids' => []]],
            // a stage rule switched to a tag rule in the form still carries an empty list of stages
            'attended'  => [['type' => 'tag', 'tag_ids' => [9], 'pipeline_id' => 1, 'stage_ids' => []]],
        ],
    ])->assertOk();

    expect($response->json('settings.default_weekdays'))->toBe([1, 2, 3, 4, 5, 6])
        ->and($response->json('settings.step_rules.attended.0'))->toEqual(['type' => 'tag', 'tag_ids' => [9]])
        ->and($response->json('settings.step_rules.scheduled.0'))->toEqual(['type' => 'appointment']);

    test()->actingAs($admin)->postJson(goalsRoute('save'), ['weekdays' => [1], 'step_rules' => ['qualified' => [['type' => 'stage', 'pipeline_id' => 1, 'stage_ids' => []]]]])->assertStatus(422);
});

it('lists the history with who, when and readable values', function () {
    $admin = getDefaultAdmin();

    test()->actingAs($admin)->postJson(goalsRoute('plan'), goalsPlan())->assertOk();
    test()->actingAs($admin)->postJson(goalsRoute('plan'), goalsPlan(['revenue_target' => 60000]))->assertOk();

    $items = collect(test()->actingAs($admin)->getJson(goalsRoute('history').'?month=2026-10')->assertOk()->json('items'));
    $revenue = $items->firstWhere('field', 'Meta de faturamento');

    expect($items->count())->toBeGreaterThan(5)
        ->and($items->first()['user'])->toBe($admin->name)
        ->and($revenue['new'])->toBe('R$ 60.000')
        ->and($items->where('field', 'Meta de faturamento')->pluck('old')->all())->toContain('R$ 50.000', '—')
        ->and(GoalChange::query()->where('field', 'revenue_target')->count())->toBe(2);
});
