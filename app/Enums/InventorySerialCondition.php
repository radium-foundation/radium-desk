<?php

namespace App\Enums;

enum InventorySerialCondition: string
{
    case New = 'new';
    case Used = 'used';
    case Refurbished = 'refurbished';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Used => 'Used',
            self::Refurbished => 'Refurbished',
        };
    }

    public static function tryFromLabel(string $value): ?self
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'new' => self::New,
            'used' => self::Used,
            'refurbished' => self::Refurbished,
            default => self::tryFrom($normalized),
        };
    }
}
