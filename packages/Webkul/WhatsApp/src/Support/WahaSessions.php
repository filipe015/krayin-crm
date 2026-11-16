<?php

namespace Webkul\WhatsApp\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * The WAHA sessions this installation may see and control (WAHA_SESSIONS). One WAHA server
 * can serve several CRM installations, each with its own sessions; an empty list keeps the
 * old behaviour (every session of the server). Cloud API numbers are never filtered here.
 */
final class WahaSessions
{
    /**
     * @return list<string>|null  null = every session is allowed.
     */
    public static function allowed(mixed $value = null): ?array
    {
        $value ??= config('whatsapp.waha.sessions');

        $names = is_array($value) ? $value : explode(',', (string) $value);
        $names = array_values(array_unique(array_filter(array_map(fn ($name) => trim((string) $name), $names), fn ($name) => $name !== '')));

        return $names === [] ? null : $names;
    }

    public static function allows(string $session): bool
    {
        $allowed = self::allowed();

        return $allowed === null || in_array($session, $allowed, true);
    }

    /**
     * Restricts a whatsapp_sessions query: WAHA rows only when allowed, every other channel as is.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function scope(Builder $query): Builder
    {
        $allowed = self::allowed();

        if ($allowed === null) {
            return $query;
        }

        $table = $query->getModel()->getTable();

        return $query->where(fn (Builder $inner) => $inner
            ->where($table.'.channel', '!=', 'waha')
            ->orWhereIn($table.'.external_id', $allowed));
    }
}
