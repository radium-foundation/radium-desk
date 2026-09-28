<?php

namespace App\Support\HardwareFulfilment;

final class ExternalCourierCatalog
{
    public const OTHER_CODE = 'other';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $configured = config('shipping.external_couriers', []);

        return is_array($configured) ? $configured : [];
    }

    public static function label(string $code): ?string
    {
        return self::options()[$code] ?? null;
    }

    public static function isValidCode(string $code): bool
    {
        return array_key_exists($code, self::options());
    }

    public static function requiresDisplayName(string $code): bool
    {
        return $code === self::OTHER_CODE;
    }
}
