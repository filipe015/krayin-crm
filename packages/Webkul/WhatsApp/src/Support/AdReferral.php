<?php

namespace Webkul\WhatsApp\Support;

/**
 * Finds the "came from an ad" block (Meta click-to-WhatsApp) in a webhook message.
 *
 * WAHA/GOWS forwards WhatsApp's own protobuf as JSON, so the block is
 * `contextInfo.externalAdReply` inside whichever message type arrived
 * (`_data.Message.extendedTextMessage.contextInfo`, `imageMessage.contextInfo`…), with
 * camelCase or PascalCase keys depending on the engine version. The Cloud API sends a
 * `referral` object instead (source_id, source_url, headline, ctwa_clid…). Both are found
 * anywhere in the payload, whatever the nesting or the case of the keys.
 *
 * The real GOWS shape was not confirmed against a real ad yet: the search is deliberately
 * tolerant.
 */
final class AdReferral
{
    private const MAX_DEPTH = 10;

    /**
     * Normalised key (lowercase, no "_") => field of the result.
     */
    private const FIELDS = [
        'sourceid'     => 'source_id',
        'sourceurl'    => 'source_url',
        'sourcetype'   => 'source_type',
        'title'        => 'title',
        'headline'     => 'title',
        'body'         => 'body',
        'ctwaclid'     => 'ctwa_clid',
        'thumbnailurl' => 'thumbnail_url',
        'mediaurl'     => 'media_url',
        'imageurl'     => 'media_url',
        'videourl'     => 'media_url',
    ];

    /**
     * @param  array<mixed>  $payload
     * @return array{source_id: ?string, source_url: ?string, source_type: ?string, title: ?string, body: ?string, ctwa_clid: ?string, thumbnail_url: ?string, media_url: ?string}|null
     */
    public static function extract(array $payload): ?array
    {
        foreach (self::candidates($payload, 0) as $block) {
            $ad = self::normalise($block);

            if ($ad !== null) {
                return $ad;
            }
        }

        return null;
    }

    /**
     * Every array under an "externalAdReply" or "referral" key, outermost first.
     *
     * @param  array<mixed>  $node
     * @return \Generator<int, array<mixed>>
     */
    private static function candidates(array $node, int $depth): \Generator
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        $nested = [];

        foreach ($node as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if (is_string($key) && in_array(self::key($key), ['externaladreply', 'referral'], true)) {
                yield $value;
            } else {
                $nested[] = $value;
            }
        }

        foreach ($nested as $value) {
            yield from self::candidates($value, $depth + 1);
        }
    }

    /**
     * @param  array<mixed>  $block
     */
    private static function normalise(array $block): ?array
    {
        $ad = array_fill_keys(array_unique(array_values(self::FIELDS)), null);

        foreach ($block as $key => $value) {
            $field = is_string($key) ? (self::FIELDS[self::key($key)] ?? null) : null;

            if ($field === null || $ad[$field] !== null) {
                continue;
            }

            $ad[$field] = self::text($value);
        }

        // Without the ad id or the click id this is a link preview (a shared post, for
        // instance), not a click on an ad.
        if ($ad['source_id'] === null && $ad['ctwa_clid'] === null) {
            return null;
        }

        return $ad;
    }

    private static function key(string $key): string
    {
        return strtolower(str_replace(['_', '-'], '', $key));
    }

    private static function text(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 2000);
    }
}
