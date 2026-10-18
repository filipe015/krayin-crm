<?php

namespace Webkul\Goals\Support;

/**
 * The funnel worked backwards, in whole numbers.
 *
 *   revenue ÷ ticket            = sales
 *   sales ÷ closing rate        = people who attended
 *   attended ÷ attendance rate  = appointments
 *   appointments ÷ scheduling rate = qualified leads
 *   qualified ÷ qualification rate = leads
 *
 * Every step is rounded UP, so the plan never asks for less than the goal needs. A step the person
 * fixed by hand is kept as it is, and the steps before it are worked out from that number. Pure
 * calculation: no database, no app.
 */
final class GoalPlanner
{
    /** The funnel, from the first step to the sale. */
    public const STEPS = ['leads', 'qualified', 'scheduled', 'attended', 'sales'];

    /** The rate that takes a step to the next one (percent, 1-100). */
    public const RATE_AFTER = [
        'leads'     => 'qualification_rate',
        'qualified' => 'scheduling_rate',
        'scheduled' => 'attendance_rate',
        'attended'  => 'closing_rate',
    ];

    /**
     * @param  array<string, int|null>  $input  revenue_target, ticket, the four rates, cpl_target, and the
     *                                          <step>_target the person fixed by hand (listed in $fixed)
     * @param  list<string>  $fixed  step names (e.g. "scheduled") whose <step>_target is to be kept
     * @return array<string, int|null> <step>_target for every step, revenue_implied, budget_needed
     */
    public static function plan(array $input, array $fixed = []): array
    {
        $value = fn (string $key) => isset($input[$key]) && $input[$key] !== '' ? (int) $input[$key] : null;
        $pinned = fn (string $step) => in_array($step, $fixed, true) ? $value($step.'_target') : null;

        $revenue = $value('revenue_target');
        $ticket = $value('ticket');

        $targets = [];

        // Sales come from revenue and ticket; every other step from the one after it.
        $targets['sales'] = $pinned('sales')
            ?? ($revenue !== null && $ticket ? self::ceilDiv($revenue, $ticket) : null);

        foreach (array_reverse(array_slice(self::STEPS, 0, -1)) as $step) {
            $next = self::STEPS[array_search($step, self::STEPS, true) + 1];
            $rate = $value(self::RATE_AFTER[$step]);

            $targets[$step] = $pinned($step)
                ?? ($targets[$next] !== null && $rate ? self::ceilDiv($targets[$next] * 100, $rate) : null);
        }

        $cpl = $value('cpl_target');

        $result = [];

        foreach (self::STEPS as $step) {
            $result[$step.'_target'] = $targets[$step];
        }

        // What the sales target is worth, and what the leads cost: for the person to compare with the goal.
        $result['revenue_implied'] = $targets['sales'] !== null && $ticket ? $targets['sales'] * $ticket : null;
        $result['budget_needed'] = $targets['leads'] !== null && $cpl ? $targets['leads'] * $cpl : null;

        return $result;
    }

    /**
     * Whether a rate is acceptable: a whole percent from 1 to 100 (0 would divide by nothing).
     */
    public static function validRate(mixed $rate): bool
    {
        return is_int($rate) && $rate >= 1 && $rate <= 100;
    }

    private static function ceilDiv(int $number, int $by): int
    {
        return intdiv($number + $by - 1, $by);
    }
}
