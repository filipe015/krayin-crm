<?php

namespace Webkul\WhatsApp\Support;

class PhoneNumberMatcher
{
    public static function matches(string $first, string $second): bool
    {
        $first = self::normalize($first);
        $second = self::normalize($second);

        if ($first === $second) {
            return true;
        }

        if (array_intersect(self::brazilianVariants($first), self::brazilianVariants($second)) !== []) {
            return true;
        }

        [$shorter, $longer] = strlen($first) < strlen($second)
            ? [$first, $second]
            : [$second, $first];

        return strlen($shorter) >= 8 && str_ends_with($longer, $shorter);
    }

    /**
     * Every spelling under which the same number may be stored, country code included,
     * so it can be used in an IN () lookup.
     *
     * @return list<string>
     */
    public static function variants(string $phone): array
    {
        $digits = self::normalize($phone);

        // Only a number that already carries the 55 prefix is safe to read as Brazilian;
        // 10-11 digits without it could just as well be another country's number.
        if (! in_array(strlen($digits), [12, 13], true) || ! str_starts_with($digits, '55')) {
            return [$digits];
        }

        return array_map(fn (string $number) => '55'.$number, self::brazilianVariants($digits)) ?: [$digits];
    }

    /**
     * Brazilian mobile numbers may show up with or without the ninth digit
     * (e.g. 553198966398 and 5531998966398), and with or without the 55 prefix.
     * Returns the national forms (DDD + number) of both spellings, or an empty
     * list when the number does not look Brazilian.
     *
     * @return list<string>
     */
    private static function brazilianVariants(string $digits): array
    {
        $length = strlen($digits);

        if (in_array($length, [12, 13], true) && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
            $length -= 2;
        }

        if (! in_array($length, [10, 11], true) || (int) substr($digits, 0, 2) < 11) {
            return [];
        }

        $ddd = substr($digits, 0, 2);
        $local = substr($digits, 2);

        if ($length === 10 && $local[0] >= '6') {
            return [$digits, $ddd.'9'.$local];
        }

        if ($length === 11 && $local[0] === '9' && $local[1] >= '6') {
            return [$digits, $ddd.substr($local, 1)];
        }

        return [$digits];
    }

    private static function normalize(string $phone): string
    {
        return preg_replace('/\D/', '', $phone);
    }
}
