<?php

use Webkul\Goals\Support\GoalSplitter;

/**
 * Whole numbers spread over days: they always add up to the total.
 */
it('adds up exactly to the total', function (int $total, int $slots) {
    $parts = GoalSplitter::distribute($total, $slots);

    expect($parts)->toHaveCount($slots)
        ->and(array_sum($parts))->toBe($total);
})->with([[50, 22], [20, 22], [1, 22], [0, 22], [252, 21], [7, 7], [100, 3], [999999, 23]]);

it('spreads the extra units evenly: only two values, one apart', function () {
    $parts = GoalSplitter::distribute(50, 22);

    expect(min($parts))->toBe(2)
        ->and(max($parts))->toBe(3)
        ->and(count(array_filter($parts, fn ($part) => $part === 3)))->toBe(6);
});

it('does not bunch the extras together', function () {
    $parts = GoalSplitter::distribute(5, 20);

    // five 1s among twenty days: never two in a row
    foreach (range(0, 18) as $index) {
        expect($parts[$index] + $parts[$index + 1])->toBeLessThanOrEqual(1);
    }
});

it('gives nothing to some days when the total is smaller than the days', function () {
    $parts = GoalSplitter::distribute(3, 10);

    expect(array_sum($parts))->toBe(3)
        ->and(max($parts))->toBe(1);
});

it('has no parts without days', function () {
    expect(GoalSplitter::distribute(50, 0))->toBe([]);
});

it('gives every day the same when the total divides evenly', function () {
    expect(GoalSplitter::distribute(44, 22))->toBe(array_fill(0, 22, 2));
});
