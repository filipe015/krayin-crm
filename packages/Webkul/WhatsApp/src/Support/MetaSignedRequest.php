<?php

namespace Webkul\WhatsApp\Support;

/**
 * Meta's "signed_request" (sent to the data deletion callback): "<signature>.<payload>", both
 * base64url, the signature being HMAC-SHA256 of the encoded payload with the app secret.
 */
class MetaSignedRequest
{
    /**
     * The app secret in use: the WhatsApp Cloud one, or META_APP_SECRET when it is empty.
     */
    public static function secret(): string
    {
        return (string) (config('whatsapp.cloud.app_secret') ?: config('legal.meta_app_secret'));
    }

    /**
     * The decoded payload when the signature checks out, null otherwise.
     */
    public static function parse(?string $signedRequest, string $secret): ?array
    {
        if ($secret === '' || ! is_string($signedRequest) || substr_count($signedRequest, '.') !== 1) {
            return null;
        }

        [$encodedSignature, $encodedPayload] = explode('.', $signedRequest, 2);

        $signature = self::base64UrlDecode($encodedSignature);
        $expected = hash_hmac('sha256', $encodedPayload, $secret, true);

        if ($signature === null || ! hash_equals($expected, $signature)) {
            return null;
        }

        $json = self::base64UrlDecode($encodedPayload);
        $payload = $json === null ? null : json_decode($json, true);

        if (! is_array($payload) || strtoupper((string) ($payload['algorithm'] ?? '')) !== 'HMAC-SHA256') {
            return null;
        }

        return $payload;
    }

    /**
     * Same user, same code: Meta may call more than once and the status page needs no database.
     */
    public static function confirmationCode(string $userId, string $secret): string
    {
        return strtoupper(substr(hash_hmac('sha256', 'data-deletion:'.$userId, $secret), 0, 16));
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
