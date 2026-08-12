<?php

namespace App\Enums;

enum LeaveAmendmentType: string
{
    case Cancellation = 'cancellation';
    case DateChange = 'date_change';

    public function label(): string
    {
        return match ($this) {
            self::Cancellation => 'Cancellation',
            self::DateChange => 'Date Change',
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
