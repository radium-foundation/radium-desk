<?php

namespace App\Enums;

enum TimelineDayBucket: string
{
    case Today = 'today';
    case Yesterday = 'yesterday';
    case Earlier = 'earlier';

    public function label(): string
    {
        return match ($this) {
            self::Today => 'Today',
            self::Yesterday => 'Yesterday',
            self::Earlier => 'Earlier',
        };
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::Today => 0,
            self::Yesterday => 1,
            self::Earlier => 2,
        };
    }
}
