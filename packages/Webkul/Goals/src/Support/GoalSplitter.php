<?php

namespace Webkul\Goals\Support;

/**
 * Spreads a whole number over a number of days without breaking it: 50 appointments over 22 working
 * days are 2s and 3s that add up to exactly 50, spread evenly through the month (never 2.27 a day).
 */
final class GoalSplitter
{
    /**
     * @return list<int> one whole number per slot; they add up to $total
     */
    public static function distribute(int $total, int $slots): array
    {
        if ($slots <= 0) {
            return [];
        }

        $parts = [];

        for ($index = 0; $index < $slots; $index++) {
            // The running total after this slot, minus the one before it: the extra units land evenly.
            $parts[] = intdiv(($index + 1) * $total, $slots) - intdiv($index * $total, $slots);
        }

        return $parts;
    }
}
