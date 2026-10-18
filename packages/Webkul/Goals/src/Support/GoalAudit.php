<?php

namespace Webkul\Goals\Support;

use Webkul\Goals\Models\GoalChange;

/**
 * The history of edits: one row for each field that changed, with who and when. Anything that
 * changes a plan, a month or the settings goes through here.
 */
final class GoalAudit
{
    /**
     * Records the fields whose value differs between the old and the new state.
     *
     * @param  array<string, mixed>  $old  field => value before (missing = it did not exist)
     * @param  array<string, mixed>  $new  field => value after
     */
    public static function diff(string $subject, array $old, array $new, ?int $userId, ?string $month = null, string $funnel = ''): int
    {
        $recorded = 0;

        foreach ($new as $field => $value) {
            $before = self::text($old[$field] ?? null);
            $after = self::text($value);

            if ($before === $after) {
                continue;
            }

            GoalChange::query()->create([
                'user_id'    => $userId,
                'subject'    => $subject,
                'year_month' => $month,
                'funnel'     => $funnel,
                'field'      => $field,
                'old_value'  => $before,
                'new_value'  => $after,
                'changed_at' => now(),
            ]);

            $recorded++;
        }

        return $recorded;
    }

    /**
     * A plan (or anything else) was removed: one row saying so.
     */
    public static function removed(string $subject, ?int $userId, ?string $month = null, string $funnel = ''): void
    {
        GoalChange::query()->create([
            'user_id'    => $userId,
            'subject'    => $subject,
            'year_month' => $month,
            'funnel'     => $funnel,
            'field'      => 'removed',
            'old_value'  => null,
            'new_value'  => null,
            'changed_at' => now(),
        ]);
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;
    }
}
