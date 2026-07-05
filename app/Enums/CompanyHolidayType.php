<?php

namespace App\Enums;

enum CompanyHolidayType: string
{
    case National = 'national';
    case Festival = 'festival';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::National => 'National',
            self::Festival => 'Festival',
            self::Company => 'Company',
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
