<?php

namespace Webkul\Goals\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Webkul\Goals\Models\GoalMonth;
use Webkul\Goals\Models\GoalPlan;

/**
 * The goal plans of each month (general, and one per funnel): saving them (worked out by GoalPlanner,
 * every change written to the history), the month's working days, copying a month to the next, and the
 * targets of any period split into whole numbers per day.
 */
final class GoalPlanService
{
    /** What the person sets. */
    public const INPUTS = ['revenue_target', 'ticket', 'qualification_rate', 'scheduling_rate', 'attendance_rate', 'closing_rate', 'cpl_target', 'budget'];

    /** The funnel's needs: worked out, or fixed by hand. */
    public const TARGETS = ['leads_target', 'qualified_target', 'scheduled_target', 'attended_target', 'sales_target'];

    /** The numbers that are spread over the working days. */
    public const METRICS = ['revenue_target', 'leads_target', 'qualified_target', 'scheduled_target', 'attended_target', 'sales_target', 'budget'];

    /**
     * Saves the plan of a month ('' funnel = the general plan). The funnel's needs are worked out from
     * the revenue, the ticket and the rates; a step in $fixed keeps the number given for it.
     *
     * @param  array<string, mixed>  $input  INPUTS, plus <step>_target for the steps in $fixed
     * @param  list<string>  $fixed  funnel steps ("leads", "qualified", ...) fixed by hand
     */
    public static function save(string $month, string $funnel, array $input, array $fixed = [], ?int $userId = null): GoalPlan
    {
        self::assertMonth($month);

        $funnel = mb_substr(trim($funnel), 0, 120);
        $values = self::clean($input);

        // Only a step that was given a number can be fixed.
        $fixed = array_values(array_filter(
            array_intersect($fixed, GoalPlanner::STEPS),
            fn (string $step) => isset($input[$step.'_target']) && $input[$step.'_target'] !== '' && $input[$step.'_target'] !== null,
        ));

        $given = [];

        foreach ($fixed as $step) {
            $given[$step.'_target'] = self::wholeNumber($input[$step.'_target'], $step.'_target');
        }

        $planned = GoalPlanner::plan($values + $given, $fixed);

        $attributes = $values + array_intersect_key($planned, array_flip(self::TARGETS)) + ['overrides' => $fixed];

        $existing = GoalPlan::query()->where('year_month', $month)->where('funnel', $funnel)->first();

        GoalAudit::diff('plan', $existing ? $existing->only(array_keys($attributes)) : [], $attributes, $userId, $month, $funnel);

        return GoalPlan::query()->updateOrCreate(
            ['year_month' => $month, 'funnel' => $funnel],
            $attributes + ['updated_by' => $userId],
        );
    }

    public static function delete(string $month, string $funnel, ?int $userId = null): bool
    {
        $plan = GoalPlan::query()->where('year_month', $month)->where('funnel', $funnel)->first();

        if (! $plan) {
            return false;
        }

        $plan->delete();

        GoalAudit::removed('plan', $userId, $month, $funnel);

        return true;
    }

    /**
     * Every plan of the month, the general one first.
     *
     * @return Collection<int, GoalPlan>
     */
    public static function plans(string $month): Collection
    {
        return GoalPlan::query()->where('year_month', $month)->orderBy('funnel')->get();
    }

    /**
     * Copies the plans of one month to another (the funnels' shares and the rates included).
     */
    public static function copyMonth(string $from, string $to, ?int $userId = null): int
    {
        self::assertMonth($from);
        self::assertMonth($to);

        $copied = 0;

        foreach (self::plans($from) as $plan) {
            $input = $plan->only(self::INPUTS);

            foreach ($plan->overrides ?? [] as $step) {
                $input[$step.'_target'] = $plan->{$step.'_target'};
            }

            self::save($to, $plan->funnel, $input, $plan->overrides ?? [], $userId);

            $copied++;
        }

        return $copied;
    }

    /**
     * The working days of a month: the ones picked for it, or else the default weekdays.
     *
     * @return list<string>
     */
    public static function workingDays(string $month): array
    {
        $row = GoalMonth::query()->where('year_month', $month)->first();

        if ($row && $row->working_days) {
            return GoalCalendar::onlyThisMonth($month, $row->working_days);
        }

        return GoalCalendar::defaultWorkingDays($month, GoalSettingsService::get()['default_weekdays']);
    }

    /**
     * @param  list<string>  $dates  the working days picked ('Y-m-d', inside the month)
     * @return list<string> the days saved
     */
    public static function saveWorkingDays(string $month, array $dates, ?int $userId = null): array
    {
        self::assertMonth($month);

        $days = GoalCalendar::onlyThisMonth($month, $dates);

        if (! $days) {
            throw new InvalidArgumentException('Pick at least one working day of the month.');
        }

        GoalAudit::diff('month', ['working_days' => self::workingDays($month)], ['working_days' => $days], $userId, $month);

        GoalMonth::query()->updateOrCreate(['year_month' => $month], ['working_days' => $days]);

        return $days;
    }

    /**
     * Each number of the plan as whole numbers per working day (they add up to the plan's number).
     *
     * @param  list<string>  $workingDays
     * @return array<string, array<string, int>> metric => [date => number]
     */
    public static function daily(GoalPlan $plan, array $workingDays): array
    {
        $daily = [];

        foreach (self::METRICS as $metric) {
            if ($plan->{$metric} === null) {
                continue;
            }

            $daily[$metric] = array_combine($workingDays, GoalSplitter::distribute((int) $plan->{$metric}, count($workingDays)));
        }

        return $daily;
    }

    /**
     * The target of any period (a day, a week, a month, or any stretch of days), adding up the days
     * of every month it touches. Empty when no plan covers it; zero when a plan covers it but no working day falls in it.
     *
     * @return array<string, int> metric => target
     */
    public static function targetsForRange(CarbonImmutable $from, CarbonImmutable $to, string $funnel = ''): array
    {
        $totals = [];

        for ($month = $from->startOfMonth(); $month->lte($to); $month = $month->addMonth()) {
            $key = $month->format('Y-m');
            $plan = GoalPlan::query()->where('year_month', $key)->where('funnel', $funnel)->first();

            if (! $plan) {
                continue;
            }

            foreach (self::daily($plan, self::workingDays($key)) as $metric => $days) {
                // The plan covers it, so a period with no working day is a zero (not "no plan").
                $totals[$metric] ??= 0;

                foreach ($days as $date => $number) {
                    if ($date >= $from->toDateString() && $date <= $to->toDateString()) {
                        $totals[$metric] = ($totals[$metric] ?? 0) + $number;
                    }
                }
            }
        }

        return $totals;
    }

    /**
     * What the general plan asks for that the funnels' plans do not add up to (negative = they ask for
     * more). Only a warning for the person: the plans are free to differ.
     *
     * @return array<string, array{general: int, funnels: int, gap: int}>
     */
    public static function funnelGap(string $month): array
    {
        $plans = self::plans($month);
        $general = $plans->firstWhere('funnel', '');
        $funnels = $plans->where('funnel', '!=', '');

        if (! $general || $funnels->isEmpty()) {
            return [];
        }

        $gaps = [];

        foreach (self::METRICS as $metric) {
            if ($general->{$metric} === null) {
                continue;
            }

            $sum = (int) $funnels->sum($metric);

            $gaps[$metric] = ['general' => (int) $general->{$metric}, 'funnels' => $sum, 'gap' => (int) $general->{$metric} - $sum];
        }

        return $gaps;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, int|null>
     */
    private static function clean(array $input): array
    {
        $values = [];

        foreach (self::INPUTS as $key) {
            $given = $input[$key] ?? null;

            $values[$key] = $given === null || $given === '' ? null : self::wholeNumber($given, $key);
        }

        foreach (['qualification_rate', 'scheduling_rate', 'attendance_rate', 'closing_rate'] as $rate) {
            if ($values[$rate] !== null && ! GoalPlanner::validRate($values[$rate])) {
                throw new InvalidArgumentException("{$rate} must be a whole percent from 1 to 100.");
            }
        }

        if ($values['ticket'] !== null && $values['ticket'] < 1) {
            throw new InvalidArgumentException('ticket must be at least 1.');
        }

        return $values;
    }

    private static function wholeNumber(mixed $value, string $field): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);

        if ($number === false || $number < 0) {
            throw new InvalidArgumentException("{$field} must be a whole number, zero or more.");
        }

        return $number;
    }

    private static function assertMonth(string $month): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new InvalidArgumentException('The month must be like 2026-10.');
        }
    }
}
