<?php

use Webkul\WhatsApp\Support\PhoneNumberMatcher;

it('matches normalized phone numbers safely', function (string $first, string $second, bool $expected) {
    expect(PhoneNumberMatcher::matches($first, $second))->toBe($expected);
})->with([
    'country code only on received number' => ['556195684042', '6195684042', true],
    'identical numbers'                    => ['6195684042', '6195684042', true],
    'different numbers'                    => ['6195684042', '6195684099', false],
    'short suffix'                         => ['42', '556195684042', false],
    'ninth digit added, with country code' => ['553198966398', '5531998966398', true],
    'ninth digit added, both directions'   => ['5531998966398', '553198966398', true],
    'ninth digit, no country code on one'  => ['31998966398', '553198966398', true],
    'different number, formatted'          => ['(31) 99896-6398', '+55 31 8896-6398', false],
    'ninth digit, formatted number match'  => ['(31) 99896-6398', '+55 31 9896-6398', true],
    'landline is not given a ninth digit'  => ['1132224444', '11932224444', false],
    'different DDD'                        => ['11998966398', '21998966398', false],
]);

it('lists every spelling of a number for lookups', function (string $phone, array $expected) {
    expect(PhoneNumberMatcher::variants($phone))->toEqualCanonicalizing($expected);
})->with([
    'old mobile without the ninth digit' => ['556195684042', ['556195684042', '5561995684042']],
    'mobile with the ninth digit'        => ['5561995684042', ['556195684042', '5561995684042']],
    'formatted'                          => ['+55 (61) 9568-4042', ['556195684042', '5561995684042']],
    'landline'                           => ['551132224444', ['551132224444']],
    'foreign number'                     => ['14155550100', ['14155550100']],
]);
