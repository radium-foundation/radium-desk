<?php

namespace App\Enums;

enum PerformanceInsightTone: string
{
    case Good = 'good';
    case Attention = 'attention';
    case Info = 'info';

    public function badgeClass(): string
    {
        return match ($this) {
            self::Good => 'success',
            self::Attention => 'warning',
            self::Info => 'info',
        };
    }
}
