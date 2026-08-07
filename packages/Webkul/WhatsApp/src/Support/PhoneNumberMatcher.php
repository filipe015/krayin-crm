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

        [$shorter, $longer] = strlen($first) < strlen($second)
            ? [$first, $second]
            : [$second, $first];

        return strlen($shorter) >= 8 && str_ends_with($longer, $shorter);
    }

    private static function normalize(string $phone): string
    {
        return preg_replace('/\D/', '', $phone);
    }
}
