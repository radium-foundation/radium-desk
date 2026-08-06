<?php

namespace App\Enums;

enum IraMemoryStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    case Merged = 'merged';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Disabled => 'Disabled',
            self::Merged => 'Merged',
            self::Deleted => 'Deleted',
        };
    }

    public function isMatchable(): bool
    {
        return $this === self::Active;
    }
}
