<?php

namespace App\Enums;

enum PresenceStatus: string
{
    case Active = 'active';
    case Idle = 'idle';
    case Away = 'away';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Idle => 'Idle',
            self::Away => 'Away',
        };
    }

    public function indicator(): string
    {
        return match ($this) {
            self::Active => '🟢',
            self::Idle => '🟡',
            self::Away => '🔴',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Idle => 'warning',
            self::Away => 'danger',
        };
    }
}
