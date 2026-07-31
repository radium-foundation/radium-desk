<?php

namespace App\Enums;

enum LeaveDuration: string
{
    case FullDay = 'full_day';
    case HalfDay = 'half_day';

    public function label(): string
    {
        return match ($this) {
            self::FullDay => 'Full Day',
            self::HalfDay => 'Half Day',
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
