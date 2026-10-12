<?php

use Webkul\WhatsApp\Support\AgendaAssignment;

it('alternates between the people of the pool, in order, and wraps around', function () {
    $pool = [3, 1, 2];

    expect(AgendaAssignment::pick('round_robin', $pool, null))->toBe(1)
        ->and(AgendaAssignment::pick('round_robin', $pool, 1))->toBe(2)
        ->and(AgendaAssignment::pick('round_robin', $pool, 2))->toBe(3)
        ->and(AgendaAssignment::pick('round_robin', $pool, 3))->toBe(1);
});

it('skips whoever already has something at that time', function () {
    expect(AgendaAssignment::pick('round_robin', [1, 2, 3], 1, [], [2]))->toBe(3)
        ->and(AgendaAssignment::pick('round_robin', [1, 2, 3], 3, [], [1]))->toBe(2);
});

it('falls back to the pool when everybody is taken, so the conflict warning can speak', function () {
    expect(AgendaAssignment::pick('round_robin', [1, 2], null, [], [1, 2]))->toBe(1);
});

it('gives it to the least busy person, using the rotation to break ties', function () {
    $busy = [1 => 3, 2 => 1, 3 => 1];

    expect(AgendaAssignment::pick('least_busy', [1, 2, 3], null, $busy))->toBe(2)
        ->and(AgendaAssignment::pick('least_busy', [1, 2, 3], 2, $busy))->toBe(3)
        ->and(AgendaAssignment::pick('least_busy', [1, 2, 3], 3, $busy))->toBe(2);
});

it('treats a person with nothing booked as the least busy', function () {
    expect(AgendaAssignment::pick('least_busy', [1, 2], null, [1 => 2]))->toBe(2);
});

it('returns nothing for an empty pool', function () {
    expect(AgendaAssignment::pick('round_robin', [], null))->toBeNull();
});
