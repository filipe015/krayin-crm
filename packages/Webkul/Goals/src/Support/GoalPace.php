<?php

namespace Webkul\Goals\Support;

/**
 * The arithmetic of "how am I doing": the traffic light, the projection of the month and what is
 * missing per day. Pure, whole numbers only (a goal is never a fraction of a lead).
 */
final class GoalPace
{
    public const GOOD = 'good';

    public const WARN = 'warn';

    public const BAD = 'bad';

    public const NONE = 'none';

    /** From this share of the expected number on, it is a yellow light (below, red). */
    public const WARN_FROM = 80;

    /**
     * Green when the expected number was reached, yellow from 80% of it, red below.
     * No light when nothing was expected yet.
     */
    public static function light(int|float $actual, int|float|null $expected): string
    {
        if ($expected === null || $expected <= 0) {
            return self::NONE;
        }

        if ($actual >= $expected) {
            return self::GOOD;
        }

        return $actual * 100 >= $expected * self::WARN_FROM ? self::WARN : self::BAD;
    }

    /**
     * Whole percent of the target reached (not capped: 120 means 20% above).
     */
    public static function percent(int|float $actual, int|float|null $target): ?int
    {
        return $target === null || $target <= 0 ? null : (int) round($actual * 100 / $target);
    }

    /**
     * Where the month ends if the pace so far holds: actual per elapsed working day × all working days.
     */
    public static function projection(int|float $actual, int $elapsedDays, int $totalDays): ?int
    {
        return $elapsedDays <= 0 ? null : (int) round($actual / $elapsedDays * $totalDays);
    }

    /**
     * What each remaining working day has to deliver to still reach the target (rounded up).
     */
    public static function perDayNeeded(int|float $actual, int|float|null $target, int $daysLeft): ?int
    {
        if ($target === null) {
            return null;
        }

        $missing = max(0, (int) ceil($target - $actual));

        if ($missing === 0) {
            return 0;
        }

        return $daysLeft > 0 ? (int) ceil($missing / $daysLeft) : null;
    }

    /**
     * Conversion between two steps as a whole percent (null with nobody in the first step).
     */
    public static function rate(int $from, int $to): ?int
    {
        return $from <= 0 ? null : (int) round($to * 100 / $from);
    }
}
