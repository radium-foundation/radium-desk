<?php

namespace App\Data\Executive;

enum ExecutiveMetricPeriod: string
{
    case Today = 'today';
    case Yesterday = 'yesterday';
    case Last7Days = 'last_7_days';

    public function label(): string
    {
        return match ($this) {
            self::Today => 'Today',
            self::Yesterday => 'Yesterday',
            self::Last7Days => 'Last 7 Days',
        };
    }
}
