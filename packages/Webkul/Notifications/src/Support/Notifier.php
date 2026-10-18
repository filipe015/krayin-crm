<?php

namespace Webkul\Notifications\Support;

use Illuminate\Database\QueryException;
use Webkul\Notifications\Models\Notification;

/**
 * The way any part of the CRM tells people something: it shows up in the bell of the header and, for
 * those with the CRM open, as a pop-up.
 */
final class Notifier
{
    public const INFO = 'info';

    public const SUCCESS = 'success';

    public const WARNING = 'warning';

    public const DANGER = 'danger';

    /**
     * @param  int|null  $userId  null = everyone
     * @param  string|null  $dedupeKey  the same key never notifies twice
     * @return Notification|null null when it was already sent (same dedupe key)
     */
    public static function send(
        string $title,
        string $body = '',
        string $level = self::INFO,
        ?string $url = null,
        ?int $userId = null,
        ?string $dedupeKey = null,
        string $kind = 'general',
        array $data = [],
    ): ?Notification {
        if ($dedupeKey && Notification::query()->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        try {
            return Notification::query()->create([
                'kind'       => $kind,
                'level'      => $level,
                'title'      => mb_substr($title, 0, 160),
                'body'       => $body !== '' ? $body : null,
                'url'        => $url,
                'user_id'    => $userId,
                'dedupe_key' => $dedupeKey,
                'data'       => $data ?: null,
            ]);
        } catch (QueryException $exception) {
            // Two runs at the same moment: the unique key let only one through.
            if ($dedupeKey) {
                return null;
            }

            throw $exception;
        }
    }
}
