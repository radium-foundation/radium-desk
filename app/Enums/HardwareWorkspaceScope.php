<?php

namespace App\Enums;

enum HardwareWorkspaceScope: string
{
    case Active = 'active';
    case Shipped = 'shipped';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Hardware',
            self::Shipped => 'Shipped',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'info',
            self::Shipped => 'primary',
        };
    }
}
