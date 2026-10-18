<?php

use Webkul\Goals\Support\GoalPace;

it('gives the traffic light by the share of the expected number', function () {
    expect(GoalPace::light(10, 10))->toBe('good')
        ->and(GoalPace::light(12, 10))->toBe('good')
        ->and(GoalPace::light(8, 10))->toBe('warn')
        ->and(GoalPace::light(7, 10))->toBe('bad')
        ->and(GoalPace::light(0, 0))->toBe('none')
        ->and(GoalPace::light(5, null))->toBe('none');
});

it('projects the month from the pace so far', function () {
    expect(GoalPace::projection(10, 5, 20))->toBe(40)
        ->and(GoalPace::projection(7, 3, 22))->toBe(51)
        ->and(GoalPace::projection(0, 0, 22))->toBeNull();
});

it('works out what each remaining day must deliver, rounded up', function () {
    expect(GoalPace::perDayNeeded(10, 100, 9))->toBe(10)
        ->and(GoalPace::perDayNeeded(10, 100, 7))->toBe(13)
        ->and(GoalPace::perDayNeeded(100, 100, 5))->toBe(0)
        ->and(GoalPace::perDayNeeded(120, 100, 0))->toBe(0)
        ->and(GoalPace::perDayNeeded(10, 100, 0))->toBeNull()
        ->and(GoalPace::perDayNeeded(10, null, 5))->toBeNull();
});

it('gives whole percents and rates', function () {
    expect(GoalPace::percent(1, 3))->toBe(33)->and(GoalPace::percent(5, 0))->toBeNull()
        ->and(GoalPace::rate(8, 3))->toBe(38)->and(GoalPace::rate(0, 3))->toBeNull();
});
