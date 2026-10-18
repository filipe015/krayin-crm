<?php

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Goals\Models\LeadEvent;
use Webkul\Goals\Support\GoalPlanService;
use Webkul\Goals\Support\GoalProgress;
use Webkul\Goals\Support\GoalSettingsService;

uses(DatabaseTransactions::class);

/**
 * The tracking screen: counts by the day things happened, pace against the plan.
 */
function trackedLead(string $createdAt): int
{
    $stage = DB::table('lead_pipeline_stages')->orderBy('id')->first();

    $id = DB::table('leads')->insertGetId([
        'title'                  => 'Pace test',
        'lead_value'             => 1000,
        'status'                 => 1,
        'lead_pipeline_id'       => $stage->lead_pipeline_id,
        'lead_pipeline_stage_id' => $stage->id,
        'created_at'             => $createdAt,
        'updated_at'             => $createdAt,
    ]);

    // a raw insert records nothing: write the "created" event for the day the test wants
    LeadEvent::query()->create(['lead_id' => $id, 'kind' => LeadEvent::CREATED, 'occurred_at' => $createdAt]);

    return $id;
}

it('has no numbers without a plan', function () {
    $data = GoalProgress::for('2026-10', '', CarbonImmutable::parse('2026-10-14'));

    expect($data['has_plan'])->toBeFalse()->and($data)->not->toHaveKey('steps');
});

it('counts what happened by its date against what the plan expected by today', function () {
    GoalSettingsService::save(['qualified' => [['type' => 'appointment']], 'scheduled' => [['type' => 'appointment']], 'attended' => []], [1, 2, 3, 4, 5]);
    GoalPlanService::save('2026-10', '', ['revenue_target' => 50000, 'ticket' => 2500, 'qualification_rate' => 50, 'scheduling_rate' => 50, 'attendance_rate' => 80, 'closing_rate' => 40, 'cpl_target' => 30, 'budget' => 8000]);

    $inside = trackedLead('2026-10-05 10:00:00');
    trackedLead('2026-10-06 10:00:00');
    trackedLead('2026-09-30 10:00:00'); // before the month: not counted

    LeadEvent::query()->create(['lead_id' => $inside, 'kind' => LeadEvent::APPOINTMENT_CREATED, 'occurred_at' => '2026-10-07 09:00:00']);

    $data = GoalProgress::for('2026-10', '', CarbonImmutable::parse('2026-10-14'));
    $steps = collect($data['steps'])->keyBy('step');

    expect($data['has_plan'])->toBeTrue()
        ->and($steps['leads']['actual'])->toBe(2)
        ->and($steps['leads']['target'])->toBe(252)
        ->and($steps['scheduled']['actual'])->toBe(1)
        ->and($data['working_days']['elapsed'])->toBe(10)
        ->and($data['working_days']['total'])->toBe(22)
        ->and($steps['leads']['light'])->toBe('bad')
        ->and($steps['leads']['projection'])->toBe(4)
        ->and($steps['leads']['missing'])->toBe(250)
        ->and($steps['leads']['per_day'])->toBe(20)
        ->and($data['periods']['day']['steps'][0]['step'])->toBe('leads');
});

it('is green when the pace is enough', function () {
    GoalSettingsService::save(['qualified' => [], 'scheduled' => [], 'attended' => []], [1, 2, 3, 4, 5]);
    GoalPlanService::save('2026-10', '', ['revenue_target' => 1000, 'ticket' => 1000, 'qualification_rate' => 100, 'scheduling_rate' => 100, 'attendance_rate' => 100, 'closing_rate' => 100]);

    trackedLead('2026-10-01 10:00:00');

    $steps = collect(GoalProgress::for('2026-10', '', CarbonImmutable::parse('2026-10-30'))['steps'])->keyBy('step');

    expect($steps['leads']['target'])->toBe(1)->and($steps['leads']['light'])->toBe('good')->and($steps['leads']['per_day'])->toBe(0);
});
