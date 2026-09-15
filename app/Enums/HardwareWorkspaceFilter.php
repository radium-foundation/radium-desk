<?php

namespace App\Enums;

enum HardwareWorkspaceFilter: string
{
    case All = 'all';
    case Ready = 'ready';
    case Exceptions = 'exceptions';
    case Pickup = 'pickup';
    case Scheduled = 'scheduled';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All',
            self::Ready => 'Ready',
            self::Exceptions => 'Exceptions',
            self::Pickup => 'Pickup',
            self::Scheduled => 'Scheduled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::All => 'secondary',
            self::Ready => 'info',
            self::Exceptions => 'danger',
            self::Pickup => 'warning',
            self::Scheduled => 'secondary',
        };
    }
}
