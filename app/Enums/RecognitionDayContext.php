<?php

namespace App\Enums;

enum RecognitionDayContext: string
{
    case WeeklyOff = 'weekly_off';
    case CompanyHoliday = 'company_holiday';

    public function label(): string
    {
        return match ($this) {
            self::WeeklyOff => 'Weekly Off',
            self::CompanyHoliday => 'Company Holiday',
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
