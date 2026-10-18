<?php

namespace Webkul\Goals\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The days of a goal month: which ones count as working days, and how they fall into weeks.
 * Dates are 'Y-m-d' strings; weeks are ISO weeks (Monday to Sunday).
 */
final class GoalCalendar
{
    /** Monday to Friday (ISO weekday numbers). */
    public const DEFAULT_WEEKDAYS = [1, 2, 3, 4, 5];

    /**
     * Every day of the month ('Y-m').
     *
     * @return list<string>
     */
    public static function days(string $month): array
    {
        $day = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $last = $day->endOfMonth()->startOfDay();
        $days = [];

        for (; $day->lte($last); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    /**
     * The working days of the month, by default the given weekdays.
     *
     * @param  list<int>  $weekdays  ISO weekday numbers (1 = Monday ... 7 = Sunday)
     * @return list<string>
     */
    public static function defaultWorkingDays(string $month, array $weekdays = self::DEFAULT_WEEKDAYS): array
    {
        return array_values(array_filter(
            self::days($month),
            fn (string $date) => in_array((int) CarbonImmutable::parse($date)->isoWeekday(), $weekdays, true),
        ));
    }

    /**
     * The days a person picked, kept only if they belong to the month, in order and without repeats.
     *
     * @param  list<string>  $picked
     * @return list<string>
     */
    public static function onlyThisMonth(string $month, array $picked): array
    {
        $valid = array_intersect(self::days($month), $picked);

        return array_values(array_unique($valid));
    }

    /**
     * The ISO weeks that touch the month, each with the days of the month it holds.
     *
     * @return list<array{week: string, start: string, end: string, days: list<string>}>
     */
    public static function weeks(string $month): array
    {
        $weeks = [];

        foreach (self::days($month) as $date) {
            $day = CarbonImmutable::parse($date);
            $key = $day->isoWeekYear().'-W'.str_pad((string) $day->isoWeek(), 2, '0', STR_PAD_LEFT);

            $weeks[$key] ??= ['week' => $key, 'start' => $day->startOfWeek(CarbonInterface::MONDAY)->toDateString(), 'end' => $day->endOfWeek(CarbonInterface::SUNDAY)->toDateString(), 'days' => []];
            $weeks[$key]['days'][] = $date;
        }

        return array_values($weeks);
    }
}
