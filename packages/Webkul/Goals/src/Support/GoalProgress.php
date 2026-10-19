<?php

namespace Webkul\Goals\Support;

use Carbon\CarbonImmutable;
use Webkul\Goals\Models\GoalPlan;

/**
 * The "Metas" tracking screen for a month and a funnel: today, this week and the month against their
 * goals, the pace so far (traffic light), where the month ends at this pace, what is missing per
 * working day, the real conversion rates against the planned ones and the cost.
 */
final class GoalProgress
{
    /** Funnel steps in order, with the plan's goal and the rate that leads into each. */
    private const STEPS = [
        'leads'     => ['target' => 'leads_target', 'rate' => null],
        'qualified' => ['target' => 'qualified_target', 'rate' => 'qualification_rate'],
        'scheduled' => ['target' => 'scheduled_target', 'rate' => 'scheduling_rate'],
        'attended'  => ['target' => 'attended_target', 'rate' => 'attendance_rate'],
        'sales'     => ['target' => 'sales_target', 'rate' => 'closing_rate'],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function for(string $month, string $funnel = '', ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::now())->startOfDay();
        $first = CarbonImmutable::parse($month.'-01')->startOfDay();
        $last = $first->endOfMonth()->startOfDay();

        $plan = GoalPlan::query()->where('year_month', $month)->where('funnel', $funnel)->first();

        $base = [
            'month'   => $month,
            'funnel'  => $funnel,
            'today'   => $today->toDateString(),
            'funnels' => GoalPlan::query()->where('year_month', $month)->where('funnel', '!=', '')->orderBy('funnel')->pluck('funnel')->all(),
            'has_plan' => (bool) $plan,
        ];

        if (! $plan) {
            return $base;
        }

        $days = GoalPlanService::workingDays($month);
        $until = $today->lt($first) ? null : ($today->gt($last) ? $last : $today);
        $elapsed = $until ? count(array_filter($days, fn ($day) => $day <= $until->toDateString())) : 0;
        $left = count($days) - $elapsed;
        // today still counts as a day to deliver while it is a working day
        $remaining = $until && $until->eq($today) && in_array($today->toDateString(), $days, true) ? $left + 1 : $left;

        $actual = $until ? GoalActuals::between($first, $until, $funnel) : self::empty();
        $expected = $until ? GoalPlanService::targetsForRange($first, $until, $funnel) : [];

        return $base + [
            'working_days' => ['total' => count($days), 'elapsed' => $elapsed, 'left' => $remaining],
            'steps'        => self::steps($plan, $actual, $expected, $elapsed, count($days), $remaining),
            'revenue'      => self::line($plan->revenue_target, $expected['revenue_target'] ?? null, $actual['revenue'], $elapsed, count($days), $remaining),
            'rates'        => self::rates($plan, $actual),
            'cost'         => self::cost($plan, $actual),
            'periods'      => self::periods($today, $first, $last, $funnel),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function steps(GoalPlan $plan, array $actual, array $expected, int $elapsed, int $total, int $remaining): array
    {
        $rows = [];

        foreach (self::STEPS as $step => $map) {
            $rows[] = ['step' => $step] + self::line($plan->{$map['target']}, $expected[$map['target']] ?? null, $actual[$step], $elapsed, $total, $remaining);
        }

        return $rows;
    }

    /**
     * One number against its goal: the month's goal, what was expected by today, what happened.
     *
     * @return array<string, mixed>
     */
    private static function line(?int $target, ?int $expected, int $actual, int $elapsed, int $total, int $remaining): array
    {
        $projection = GoalPace::projection($actual, $elapsed, $total);

        return [
            'target'         => $target,
            'expected'       => $expected,
            'actual'         => $actual,
            'percent'        => GoalPace::percent($actual, $target),
            'light'          => GoalPace::light($actual, $expected),
            'projection'     => $projection,
            'projection_light' => $target === null || $projection === null ? GoalPace::NONE : GoalPace::light($projection, $target),
            'missing'        => $target === null ? null : max(0, $target - $actual),
            'per_day'        => GoalPace::perDayNeeded($actual, $target, $remaining),
        ];
    }

    /**
     * The real conversion between each pair of steps against the rate planned for it.
     *
     * @return list<array<string, mixed>>
     */
    private static function rates(GoalPlan $plan, array $actual): array
    {
        $rows = [];
        $previous = null;

        foreach (self::STEPS as $step => $map) {
            if ($previous !== null) {
                $real = GoalPace::rate($actual[$previous], $actual[$step]);
                $planned = $plan->{$map['rate']};

                $rows[] = [
                    'from'    => $previous,
                    'to'      => $step,
                    'actual'  => $real,
                    'planned' => $planned,
                    'light'   => $real === null || $planned === null ? GoalPace::NONE : GoalPace::light($real, $planned),
                ];
            }

            $previous = $step;
        }

        return $rows;
    }

    /**
     * Spend against the budget and the real cost per lead against the target (a lower cost is better).
     *
     * @return array<string, mixed>
     */
    private static function cost(GoalPlan $plan, array $actual): array
    {
        $cpl = $actual['leads'] > 0 ? (int) round($actual['spend'] / $actual['leads']) : null;

        return [
            'spend'      => $actual['spend'],
            'budget'     => $plan->budget,
            'budget_percent' => GoalPace::percent($actual['spend'], $plan->budget),
            'cpl'        => $cpl,
            'cpl_target' => $plan->cpl_target,
            'cpl_light'  => $cpl === null || $plan->cpl_target === null ? GoalPace::NONE : (GoalPace::light($plan->cpl_target, $cpl)),
        ];
    }

    /**
     * This week (Monday–Sunday) and the month, each against its own goal.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function periods(CarbonImmutable $today, CarbonImmutable $first, CarbonImmutable $last, string $funnel): array
    {
        $ranges = [
            'week'  => [$today->startOfWeek(CarbonImmutable::MONDAY), $today->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay()],
            'month' => [$first, $last],
        ];

        $out = [];

        foreach ($ranges as $name => [$from, $to]) {
            $targets = GoalPlanService::targetsForRange($from, $to, $funnel);
            $upTo = $today->lt($to) ? $today : $to;
            $actual = $upTo->lt($from) ? self::empty() : GoalActuals::between($from, $upTo, $funnel);

            $out[$name] = [
                'from'  => $from->toDateString(),
                'to'    => $to->toDateString(),
                'steps' => array_map(fn ($step, $map) => [
                    'step'    => $step,
                    'target'  => $targets[$map['target']] ?? null,
                    'actual'  => $actual[$step],
                    'percent' => GoalPace::percent($actual[$step], $targets[$map['target']] ?? null),
                ], array_keys(self::STEPS), self::STEPS),
                'revenue' => ['target' => $targets['revenue_target'] ?? null, 'actual' => $actual['revenue'], 'percent' => GoalPace::percent($actual['revenue'], $targets['revenue_target'] ?? null)],
            ];
        }

        return $out;
    }

    private static function empty(): array
    {
        return ['leads' => 0, 'qualified' => 0, 'scheduled' => 0, 'attended' => 0, 'sales' => 0, 'revenue' => 0, 'spend' => 0];
    }
}
