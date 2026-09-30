<?php

namespace App\Support;

final class ExamPrice
{
    private const MAX_INTEGER_DIGITS = 10;

    public static function normalize(mixed $value, ?string $token): ?string
    {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || $token === null) {
            return null;
        }

        if (preg_match('/\A(?<integer>\d+)(?:\.(?<fraction>\d{1,2}))?\z/', $token, $matches) !== 1) {
            return null;
        }

        $integer = ltrim($matches['integer'], '0');
        if (strlen($integer === '' ? '0' : $integer) > self::MAX_INTEGER_DIGITS) {
            return null;
        }

        $fraction = str_pad($matches['fraction'] ?? '', 2, '0');

        return ($integer === '' ? '0' : $integer).'.'.$fraction;
    }
}
