<?php

namespace Webkul\WhatsApp\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Webkul\WhatsApp\Models\LeadSetting;

/**
 * The single place that says how WhatsApp conversations become leads:
 *
 *  - the row saved in Configurações → Criação de leads pelo WhatsApp, when it exists;
 *  - otherwise the WHATSAPP_AUTO_LEAD* env (config whatsapp.auto_lead), so an installation
 *    keeps its behaviour until the screen is saved for the first time.
 *
 * Read on every incoming message: one light query, cached for a short while and forgotten
 * when the row is saved. It never throws (no table yet, cache down...): it falls back to the env.
 */
class LeadCreationSettings
{
    public const MODES = ['manual', 'ads', 'all'];

    public const CACHE_KEY = 'whatsapp.lead-creation-settings';

    public const CACHE_SECONDS = 60;

    private static bool $warned = false;

    /**
     * @return array{
     *     saved: bool,
     *     mode: string,
     *     pipeline_id: ?int,
     *     stage_id: ?int,
     *     stage: ?string,
     *     source: ?string,
     *     tags: list<string>,
     *     user_id: ?int,
     * }  stage: stage name inside the pipeline (env only; the screen stores the id).
     */
    public static function current(): array
    {
        $row = self::row();

        if ($row === null) {
            return self::fromConfig();
        }

        return [
            'saved'       => true,
            'mode'        => self::normalizeMode($row['mode'] ?? null),
            'pipeline_id' => self::id($row['pipeline_id'] ?? null),
            'stage_id'    => self::id($row['stage_id'] ?? null),
            'stage'       => null,
            'source'      => self::text($row['source'] ?? null),
            'tags'        => self::tags($row['tags'] ?? null),
            'user_id'     => self::id($row['user_id'] ?? null),
        ];
    }

    public static function mode(): string
    {
        return self::current()['mode'];
    }

    /**
     * The WHATSAPP_AUTO_LEAD* values, as they worked before the screen existed.
     */
    public static function fromConfig(): array
    {
        return [
            'saved'       => false,
            'mode'        => self::normalizeMode(config('whatsapp.auto_lead.mode', 'off')),
            'pipeline_id' => self::id(config('whatsapp.auto_lead.pipeline_id')),
            'stage_id'    => self::id(config('whatsapp.auto_lead.stage_id')),
            'stage'       => self::text(config('whatsapp.auto_lead.stage')),
            'source'      => self::text(config('whatsapp.auto_lead.source')),
            'tags'        => self::tags(config('whatsapp.auto_lead.tags', '')),
            'user_id'     => self::id(config('whatsapp.auto_lead.user_id')),
        ];
    }

    /**
     * "off" (the old env value) means "manual"; anything unknown is "manual" too.
     */
    public static function normalizeMode(mixed $mode): string
    {
        $mode = strtolower(trim((string) $mode));

        if ($mode === 'off') {
            return 'manual';
        }

        return in_array($mode, self::MODES, true) ? $mode : 'manual';
    }

    public static function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // The cache expires by itself.
        }
    }

    /**
     * The saved row as plain values, or null when nothing was saved (or it cannot be read).
     */
    private static function row(): ?array
    {
        try {
            $row = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function () {
                // false (not null) so "nothing saved" is cached too.
                return LeadSetting::query()->orderBy('id')->first()?->only(['mode', 'pipeline_id', 'stage_id', 'source', 'tags', 'user_id']) ?? false;
            });

            return is_array($row) ? $row : null;
        } catch (\Throwable $exception) {
            if (! self::$warned) {
                self::$warned = true;

                Log::warning('WhatsApp: lead creation settings could not be read, using WHATSAPP_AUTO_LEAD* from the env.', ['error' => $exception->getMessage()]);
            }

            return null;
        }
    }

    private static function id(mixed $value): ?int
    {
        return filled($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private static function tags(mixed $value): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $value)))));
    }
}
