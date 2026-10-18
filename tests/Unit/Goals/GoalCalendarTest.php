<?php

use Webkul\Goals\Support\GoalCalendar;

/**
 * The days of a goal month. October 2026 starts on a Thursday and has 31 days.
 */
it('lists every day of the month', function () {
    $days = GoalCalendar::days('2026-10');

    expect($days)->toHaveCount(31)
        ->and($days[0])->toBe('2026-10-01')
        ->and($days[30])->toBe('2026-10-31');
});

it('knows the length of any month, leap years included', function () {
    expect(GoalCalendar::days('2026-02'))->toHaveCount(28)
        ->and(GoalCalendar::days('2028-02'))->toHaveCount(29)
        ->and(GoalCalendar::days('2026-04'))->toHaveCount(30);
});

it('takes Monday to Friday as the default working days', function () {
    $days = GoalCalendar::defaultWorkingDays('2026-10');

    // 31 days, 5 Saturdays and 4 Sundays: 22 weekdays
    expect($days)->toHaveCount(22)
        ->and($days)->not->toContain('2026-10-03') // Saturday
        ->and($days)->not->toContain('2026-10-04') // Sunday
        ->and($days[0])->toBe('2026-10-01');
});

it('takes the weekdays a person chooses (Saturday included)', function () {
    $days = GoalCalendar::defaultWorkingDays('2026-10', [1, 2, 3, 4, 5, 6]);

    expect($days)->toHaveCount(27) // the 22 weekdays + 5 Saturdays
        ->and($days)->toContain('2026-10-03');
});

it('keeps only the picked days that belong to the month, in order and without repeats', function () {
    $days = GoalCalendar::onlyThisMonth('2026-10', ['2026-10-05', '2026-09-30', '2026-10-02', '2026-10-05', '2026-11-01', 'nope']);

    expect($days)->toBe(['2026-10-02', '2026-10-05']);
});

it('groups the days into ISO weeks, Monday to Sunday', function () {
    $weeks = GoalCalendar::weeks('2026-10');

    // Oct 1 is a Thursday: the first week holds only Oct 1-4; the last one Oct 26-31 (Sunday Nov 1 is out)
    expect($weeks)->toHaveCount(5)
        ->and($weeks[0]['days'])->toBe(['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04'])
        ->and($weeks[0]['start'])->toBe('2026-09-28')
        ->and($weeks[1]['days'][0])->toBe('2026-10-05')
        ->and($weeks[1]['days'])->toHaveCount(7)
        ->and(end($weeks)['days'])->toBe(['2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30', '2026-10-31'])
        ->and(array_sum(array_map(fn ($week) => count($week['days']), $weeks)))->toBe(31);
});
