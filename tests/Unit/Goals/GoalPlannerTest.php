<?php

use Webkul\Goals\Support\GoalPlanner;

/**
 * The funnel worked backwards in whole numbers, always rounding up.
 */
$full = [
    'revenue_target'     => 50000,
    'ticket'             => 2500,
    'qualification_rate' => 50,
    'scheduling_rate'    => 50,
    'attendance_rate'    => 80,
    'closing_rate'       => 40,
    'cpl_target'         => 30,
];

it('works the funnel backwards from the revenue', function () use ($full) {
    $plan = GoalPlanner::plan($full);

    // 50000 / 2500 = 20 sales; 20 / 40% = 50 attended; 50 / 80% = 63 (62.5 up); 63 / 50% = 126; 126 / 50% = 252
    expect($plan['sales_target'])->toBe(20)
        ->and($plan['attended_target'])->toBe(50)
        ->and($plan['scheduled_target'])->toBe(63)
        ->and($plan['qualified_target'])->toBe(126)
        ->and($plan['leads_target'])->toBe(252)
        ->and($plan['revenue_implied'])->toBe(50000)
        ->and($plan['budget_needed'])->toBe(7560);
});

it('rounds every step up, never down', function () {
    $plan = GoalPlanner::plan(['revenue_target' => 10000, 'ticket' => 3000, 'closing_rate' => 30]);

    // 10000 / 3000 = 3.33 -> 4 sales (worth 12000); 4 / 30% = 13.33 -> 14
    expect($plan['sales_target'])->toBe(4)
        ->and($plan['attended_target'])->toBe(14)
        ->and($plan['revenue_implied'])->toBe(12000);
});

it('gives only whole numbers', function () use ($full) {
    $plan = GoalPlanner::plan($full);

    foreach (['leads', 'qualified', 'scheduled', 'attended', 'sales'] as $step) {
        expect($plan[$step.'_target'])->toBeInt();
    }
});

it('stops at the step whose rate is missing', function () {
    $plan = GoalPlanner::plan(['revenue_target' => 50000, 'ticket' => 2500, 'closing_rate' => 40, 'attendance_rate' => 80]);

    expect($plan['sales_target'])->toBe(20)
        ->and($plan['attended_target'])->toBe(50)
        ->and($plan['scheduled_target'])->toBe(63)
        // no scheduling rate: nothing earlier can be worked out
        ->and($plan['qualified_target'])->toBeNull()
        ->and($plan['leads_target'])->toBeNull()
        ->and($plan['budget_needed'])->toBeNull();
});

it('leaves everything empty without revenue or ticket', function () {
    expect(GoalPlanner::plan(['closing_rate' => 40])['sales_target'])->toBeNull()
        ->and(GoalPlanner::plan(['revenue_target' => 5000])['sales_target'])->toBeNull();
});

it('plans zero for a zero revenue', function () use ($full) {
    $plan = GoalPlanner::plan(['revenue_target' => 0] + $full);

    expect($plan['sales_target'])->toBe(0)
        ->and($plan['leads_target'])->toBe(0);
});

it('keeps a step fixed by hand and works the steps before it from that number', function () use ($full) {
    $plan = GoalPlanner::plan(['scheduled_target' => 100] + $full, ['scheduled']);

    // the steps after it still come from the revenue; the ones before it from the 100
    expect($plan['sales_target'])->toBe(20)
        ->and($plan['attended_target'])->toBe(50)
        ->and($plan['scheduled_target'])->toBe(100)
        ->and($plan['qualified_target'])->toBe(200)
        ->and($plan['leads_target'])->toBe(400);
});

it('ignores a target that is not marked as fixed', function () use ($full) {
    expect(GoalPlanner::plan(['scheduled_target' => 100] + $full)['scheduled_target'])->toBe(63);
});

it('accepts only whole percents from 1 to 100 as a rate', function () {
    expect(GoalPlanner::validRate(1))->toBeTrue()
        ->and(GoalPlanner::validRate(100))->toBeTrue()
        ->and(GoalPlanner::validRate(0))->toBeFalse()
        ->and(GoalPlanner::validRate(101))->toBeFalse()
        ->and(GoalPlanner::validRate(12.5))->toBeFalse()
        ->and(GoalPlanner::validRate('40'))->toBeFalse();
});
