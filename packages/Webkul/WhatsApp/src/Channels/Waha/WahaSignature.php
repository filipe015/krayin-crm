<?php

namespace Webkul\WhatsApp\Channels\Waha;

/**
 * WAHA signs the raw request body with HMAC-SHA512 (hex) and sends it in X-Webhook-Hmac.
 * The X-Webhook-Timestamp header is not part of the signature, so it is not used to
 * reject replays; events are handled idempotently instead.
 */
final class WahaSignature
{
    public static function isValid(string $rawBody, ?string $signature, string $secret): bool
    {
        if (strlen($secret) < 16 || $signature === null || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $rawBody, $secret), strtolower($signature));
    }
}
