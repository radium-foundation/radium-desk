<?php

namespace App\Enums;

enum ExecutiveTrendDirection: string
{
    case Positive = 'positive';
    case Negative = 'negative';
    case Neutral = 'neutral';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Positive => 'Positive',
            self::Negative => 'Negative',
            self::Neutral => 'Neutral',
            self::Unknown => 'Unknown',
        };
    }
}
