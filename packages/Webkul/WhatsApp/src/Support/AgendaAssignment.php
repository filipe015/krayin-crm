<?php

namespace Webkul\WhatsApp\Support;

/**
 * Picks who an appointment goes to when the operator did not choose.
 */
final class AgendaAssignment
{
    /**
     * @param  list<int>  $pool  users that can take it
     * @param  array<int, int>  $busy  user id => appointments they already have that day
     * @param  list<int>  $unavailable  users with an appointment at that very time
     */
    public static function pick(string $mode, array $pool, ?int $lastAssigned, array $busy = [], array $unavailable = []): ?int
    {
        $pool = array_values(array_unique(array_map('intval', $pool)));
        sort($pool);

        if ($pool === []) {
            return null;
        }

        $free = array_values(array_diff($pool, array_map('intval', $unavailable)));

        // Everybody is taken at that time: the usual conflict warning will say so.
        $candidates = $free !== [] ? $free : $pool;

        if ($mode === 'least_busy') {
            $least = min(array_map(fn ($id) => $busy[$id] ?? 0, $candidates));
            $candidates = array_values(array_filter($candidates, fn ($id) => ($busy[$id] ?? 0) === $least));
        }

        // Whoever comes after the last person served; wraps around.
        foreach ($candidates as $id) {
            if ($lastAssigned === null || $id > $lastAssigned) {
                return $id;
            }
        }

        return $candidates[0];
    }
}
