<?php

namespace App\Enums;

enum RadiumBoxSyncSource: string
{
    case Background = 'background';
    case Scheduler = 'scheduler';
    case Manual = 'manual';
    case Trigger = 'trigger';

    public function label(): string
    {
        return match ($this) {
            self::Background => 'Background',
            self::Scheduler => 'Scheduler',
            self::Manual => 'Manual',
            self::Trigger => 'Auto trigger',
        };
    }
}
