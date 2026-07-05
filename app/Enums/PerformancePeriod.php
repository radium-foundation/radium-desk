<?php

namespace App\Enums;

enum PerformancePeriod: string
{
    case Today = 'today';
    case ThisWeek = 'this_week';
    case ThisMonth = 'this_month';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Today => 'Today',
            self::ThisWeek => 'This week',
            self::ThisMonth => 'This month',
            self::Custom => 'Custom range',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
