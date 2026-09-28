<?php

namespace App\Enums;

enum HardwareFulfilmentShippingMethod: string
{
    case Shiprocket = 'shiprocket';
    case External = 'external';

    public function label(): string
    {
        return match ($this) {
            self::Shiprocket => 'Shiprocket',
            self::External => 'Manual / External Courier',
        };
    }

    public static function tryFromNullable(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::tryFrom(trim($value));
    }
}
