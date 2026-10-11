<?php

namespace Webkul\WhatsApp\Channels\Cloud;

final class CloudSignature
{
    /**
     * Meta signs the raw body with the app secret (HMAC SHA-256) and sends it as
     * "X-Hub-Signature-256: sha256=<hex>".
     */
    public static function isValid(string $rawBody, ?string $header, string $appSecret): bool
    {
        if ($header === null || $header === '' || strlen($appSecret) < 16) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $appSecret);

        return hash_equals($expected, trim($header));
    }
}
