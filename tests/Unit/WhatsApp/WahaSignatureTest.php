<?php

use Webkul\WhatsApp\Channels\Waha\WahaSignature;

$secret = 'a-secret-with-16-plus-chars';
$body = '{"event":"message.any","session":"teste"}';

it('accepts a body signed with HMAC-SHA512', function () use ($secret, $body) {
    $signature = hash_hmac('sha512', $body, $secret);

    expect(WahaSignature::isValid($body, $signature, $secret))->toBeTrue()
        ->and(WahaSignature::isValid($body, strtoupper($signature), $secret))->toBeTrue();
});

it('rejects a tampered body, a wrong secret or a missing signature', function () use ($secret, $body) {
    $signature = hash_hmac('sha512', $body, $secret);

    expect(WahaSignature::isValid($body.' ', $signature, $secret))->toBeFalse()
        ->and(WahaSignature::isValid($body, $signature, 'another-secret-16-chars'))->toBeFalse()
        ->and(WahaSignature::isValid($body, null, $secret))->toBeFalse()
        ->and(WahaSignature::isValid($body, '', $secret))->toBeFalse();
});

it('refuses to validate when no usable secret is configured', function () use ($body) {
    expect(WahaSignature::isValid($body, hash_hmac('sha512', $body, ''), ''))->toBeFalse()
        ->and(WahaSignature::isValid($body, hash_hmac('sha512', $body, 'short'), 'short'))->toBeFalse();
});
