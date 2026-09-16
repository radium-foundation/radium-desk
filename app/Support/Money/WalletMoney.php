<?php

namespace App\Support\Money;

final class WalletMoney
{
    public const SCALE = 2;

    public static function normalize(mixed $value): ?string
    {
        if (is_int($value)) {
            if ($value < 0) {
                return null;
            }

            return sprintf('%d.00', $value);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if (preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $trimmed) !== 1) {
                return null;
            }

            return bcadd($trimmed, '0', self::SCALE);
        }

        return null;
    }

    public static function isPositive(string $amount): bool
    {
        return bccomp($amount, '0', self::SCALE) === 1;
    }
}
