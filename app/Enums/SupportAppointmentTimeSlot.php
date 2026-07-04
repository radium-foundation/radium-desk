<?php

namespace App\Enums;

enum SupportAppointmentTimeSlot: string
{
    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Evening = 'evening';

    public function label(): string
    {
        return match ($this) {
            self::Morning => 'Morning (9 AM – 12 PM)',
            self::Afternoon => 'Afternoon (12 PM – 3 PM)',
            self::Evening => 'Evening (3 PM – 6 PM)',
        };
    }
}
