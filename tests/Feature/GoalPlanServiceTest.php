<?php

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Goals\Models\GoalChange;
use Webkul\Goals\Models\GoalPlan;
use Webkul\Goals\Support\GoalPlanService;

uses(DatabaseTransactions::class);

/**
 * The monthly goal plans: saved in whole numbers, every change in the history, split into days.
 * Needs a migrated and seeded database.
 */
function planInput(array $override = []): array
{
    return $override + [
        'revenue_target'     => 50000,
        'ticket'             => 2500,
        'qualification_rate' => 50,
        'scheduling_rate'    => 50,
        'attendance_rate'    => 80,
        'closing_rate'       => 40,
        'cpl_target'         => 30,
        'budget'             => 8000,
    ];
}

function adminId(): int
{
    return (int) getDefaultAdmin()->id;
}

it('saves a plan with the funnel worked out in whole numbers', function () {
    $plan = GoalPlanService::save('2026-10', '', planInput(), [], adminId());

    expect($plan->sales_target)->toBe(20)
        ->and($plan->attended_target)->toBe(50)
        ->and($plan->scheduled_target)->toBe(63)
        ->and($plan->qualified_target)->toBe(126)
        ->and($plan->leads_target)->toBe(252)
        ->and($plan->overrides)->toBe([])
        ->and($plan->updated_by)->toBe(adminId());
});

it('keeps one plan per month and funnel, and updates it when saved again', function () {
    GoalPlanService::save('2026-10', '', planInput());
    GoalPlanService::save('2026-10', '', planInput(['revenue_target' => 75000]));
    GoalPlanService::save('2026-10', 'Lead - Online', planInput(['revenue_target' => 30000]));

    expect(GoalPlan::query()->where('year_month', '2026-10')->count())->toBe(2)
        ->and(GoalPlan::query()->where('year_month', '2026-10')->where('funnel', '')->value('sales_target'))->toBe(30);
});

it('keeps a step fixed by hand and remembers it', function () {
    $plan = GoalPlanService::save('2026-10', '', planInput() + ['scheduled_target' => 100], ['scheduled']);

    expect($plan->scheduled_target)->toBe(100)
        ->and($plan->qualified_target)->toBe(200)
        ->and($plan->overrides)->toBe(['scheduled']);

    // a target without being marked as fixed is just ignored
    $again = GoalPlanService::save('2026-10', '', planInput() + ['scheduled_target' => 100], []);

    expect($again->scheduled_target)->toBe(63)->and($again->overrides)->toBe([]);
});

it('writes every field that changed to the history, with who and when', function () {
    GoalPlanService::save('2026-10', '', planInput(), [], adminId());

    $created = GoalChange::query()->where('year_month', '2026-10')->get();

    // a new plan: each value is a change from nothing
    expect($created->pluck('field')->all())->toContain('revenue_target', 'ticket', 'sales_target', 'leads_target')
        ->and($created->every(fn ($change) => $change->old_value === null && $change->user_id === adminId()))->toBeTrue()
        ->and($created->first()->changed_at->isToday())->toBeTrue();

    GoalChange::query()->delete();

    // nothing changed: nothing recorded
    GoalPlanService::save('2026-10', '', planInput(), [], adminId());
    expect(GoalChange::query()->count())->toBe(0);

    // one field changed (and the numbers that follow from it)
    GoalPlanService::save('2026-10', '', planInput(['closing_rate' => 50]), [], adminId());

    $changes = GoalChange::query()->get()->keyBy('field');

    expect($changes['closing_rate']->old_value)->toBe('40')
        ->and($changes['closing_rate']->new_value)->toBe('50')
        ->and($changes)->not->toHaveKey('revenue_target')
        ->and($changes)->toHaveKey('attended_target');
});

it('records a plan being removed', function () {
    GoalPlanService::save('2026-10', 'Lead - Online', planInput());
    GoalChange::query()->delete();

    expect(GoalPlanService::delete('2026-10', 'Lead - Online', adminId()))->toBeTrue()
        ->and(GoalPlanService::delete('2026-10', 'Lead - Online'))->toBeFalse()
        ->and(GoalChange::query()->where('field', 'removed')->count())->toBe(1)
        ->and(GoalPlan::query()->where('year_month', '2026-10')->count())->toBe(0);
});

it('refuses numbers that are not whole, rates out of range and a bad month', function (array $override) {
    expect(fn () => GoalPlanService::save('2026-10', '', planInput($override)))->toThrow(InvalidArgumentException::class);
})->with([
    'decimal revenue' => [['revenue_target' => '1500.50']],
    'negative revenue' => [['revenue_target' => -5]],
    'rate zero' => [['closing_rate' => 0]],
    'rate over 100' => [['attendance_rate' => 101]],
    'decimal rate' => [['scheduling_rate' => '12.5']],
    'ticket zero' => [['ticket' => 0]],
    'text' => [['cpl_target' => 'abc']],
]);

it('refuses a month that is not YYYY-MM', function () {
    expect(fn () => GoalPlanService::save('2026-13', '', planInput()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => GoalPlanService::save('outubro', '', planInput()))->toThrow(InvalidArgumentException::class);
});

it('copies the plans of a month to another, funnels and fixed steps included', function () {
    GoalPlanService::save('2026-10', '', planInput() + ['scheduled_target' => 100], ['scheduled']);
    GoalPlanService::save('2026-10', 'Lead - Online', planInput(['revenue_target' => 20000]));

    expect(GoalPlanService::copyMonth('2026-10', '2026-11', adminId()))->toBe(2);

    $copy = GoalPlanService::plans('2026-11');

    expect($copy)->toHaveCount(2)
        ->and($copy->firstWhere('funnel', '')->scheduled_target)->toBe(100)
        ->and($copy->firstWhere('funnel', '')->overrides)->toBe(['scheduled'])
        ->and($copy->firstWhere('funnel', 'Lead - Online')->sales_target)->toBe(8)
        ->and(GoalChange::query()->where('year_month', '2026-11')->count())->toBeGreaterThan(0);
});

it('takes Monday to Friday as the working days, until others are picked for the month', function () {
    expect(GoalPlanService::workingDays('2026-10'))->toHaveCount(22);

    $days = array_slice(GoalPlanService::workingDays('2026-10'), 0, 20);

    GoalPlanService::saveWorkingDays('2026-10', $days, adminId());

    expect(GoalPlanService::workingDays('2026-10'))->toBe($days)
        ->and(GoalPlanService::workingDays('2026-11'))->toHaveCount(21);

    $change = GoalChange::query()->where('subject', 'month')->first();

    expect($change->field)->toBe('working_days')
        ->and(json_decode($change->old_value))->toHaveCount(22)
        ->and(json_decode($change->new_value))->toHaveCount(20)
        ->and($change->user_id)->toBe(adminId());
});

it('refuses working days that are none, or from another month', function () {
    expect(fn () => GoalPlanService::saveWorkingDays('2026-10', []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => GoalPlanService::saveWorkingDays('2026-10', ['2026-11-03']))->toThrow(InvalidArgumentException::class);
});

it('splits every number of the plan into whole numbers per working day', function () {
    $plan = GoalPlanService::save('2026-10', '', planInput());
    $days = GoalPlanService::workingDays('2026-10');
    $daily = GoalPlanService::daily($plan, $days);

    foreach (['revenue_target', 'leads_target', 'qualified_target', 'scheduled_target', 'attended_target', 'sales_target', 'budget'] as $metric) {
        expect(array_sum($daily[$metric]))->toBe((int) $plan->{$metric})
            ->and(array_keys($daily[$metric]))->toBe($days);

        foreach ($daily[$metric] as $number) {
            expect($number)->toBeInt();
        }
    }
});

it('gives the target of a day, a week and a month that agree with each other', function () {
    $plan = GoalPlanService::save('2026-10', '', planInput());
    $at = fn (string $from, string $to) => GoalPlanService::targetsForRange(CarbonImmutable::parse($from), CarbonImmutable::parse($to));

    $month = $at('2026-10-01', '2026-10-31');
    $weeks = array_map(fn ($range) => $at(...$range), [['2026-10-01', '2026-10-04'], ['2026-10-05', '2026-10-11'], ['2026-10-12', '2026-10-18'], ['2026-10-19', '2026-10-25'], ['2026-10-26', '2026-10-31']]);

    expect($month['leads_target'])->toBe($plan->leads_target)
        ->and($month['revenue_target'])->toBe((int) $plan->revenue_target)
        ->and(array_sum(array_column($weeks, 'leads_target')))->toBe($plan->leads_target)
        // a Saturday is not a working day: no target that day
        ->and($at('2026-10-03', '2026-10-03'))->toBe(['revenue_target' => 0, 'leads_target' => 0, 'qualified_target' => 0, 'scheduled_target' => 0, 'attended_target' => 0, 'sales_target' => 0, 'budget' => 0]);
});

it('adds up the days of every month a period touches, and is empty with no plan', function () {
    GoalPlanService::save('2026-10', '', planInput(['revenue_target' => 50000]));
    GoalPlanService::save('2026-11', '', planInput(['revenue_target' => 25000]));

    $both = GoalPlanService::targetsForRange(CarbonImmutable::parse('2026-10-26'), CarbonImmutable::parse('2026-11-08'));
    $october = GoalPlanService::targetsForRange(CarbonImmutable::parse('2026-10-26'), CarbonImmutable::parse('2026-10-31'));
    $november = GoalPlanService::targetsForRange(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-08'));

    expect($both['sales_target'])->toBe($october['sales_target'] + $november['sales_target'])
        ->and(GoalPlanService::targetsForRange(CarbonImmutable::parse('2026-12-01'), CarbonImmutable::parse('2026-12-31')))->toBe([])
        ->and(GoalPlanService::targetsForRange(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'), 'Lead - Online'))->toBe([]);
});

it('shows what the general plan asks for that the funnels do not add up to', function () {
    expect(GoalPlanService::funnelGap('2026-10'))->toBe([]);

    GoalPlanService::save('2026-10', '', planInput(['revenue_target' => 50000]));

    // a general plan with no funnel plans has no gap to show
    expect(GoalPlanService::funnelGap('2026-10'))->toBe([]);

    GoalPlanService::save('2026-10', 'Lead - Online', planInput(['revenue_target' => 20000]));
    GoalPlanService::save('2026-10', 'Lead - Presencial', planInput(['revenue_target' => 20000]));

    $gap = GoalPlanService::funnelGap('2026-10');

    expect($gap['revenue_target'])->toBe(['general' => 50000, 'funnels' => 40000, 'gap' => 10000])
        ->and($gap['sales_target'])->toBe(['general' => 20, 'funnels' => 16, 'gap' => 4]);

    GoalPlanService::save('2026-10', 'Lead - Bari', planInput(['revenue_target' => 20000]));

    expect(GoalPlanService::funnelGap('2026-10')['revenue_target']['gap'])->toBe(-10000);
});
