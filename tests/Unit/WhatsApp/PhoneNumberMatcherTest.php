<?php

use Webkul\WhatsApp\Support\PhoneNumberMatcher;

it('matches normalized phone numbers safely', function (string $first, string $second, bool $expected) {
    expect(PhoneNumberMatcher::matches($first, $second))->toBe($expected);
})->with([
    'country code only on received number' => ['556195684042', '6195684042', true],
    'identical numbers'                    => ['6195684042', '6195684042', true],
    'different numbers'                    => ['6195684042', '6195684099', false],
    'short suffix'                         => ['42', '556195684042', false],
]);
