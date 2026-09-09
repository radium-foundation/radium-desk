<?php

namespace App\Support\Finance;

final class IfscCode
{
    public static function normalize(?string $ifsc): ?string
    {
        if ($ifsc === null) {
            return null;
        }

        $value = strtoupper(preg_replace('/\s+/', '', trim($ifsc)) ?? '');

        return $value === '' ? null : $value;
    }

    public static function isValid(?string $ifsc): bool
    {
        $value = self::normalize($ifsc);
        if ($value === null) {
            return false;
        }

        return (bool) preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $value);
    }
}
