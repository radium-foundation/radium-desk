<?php

namespace App\Enums;

enum NotificationScheduleMode: string
{
    case Always = 'always';
    case WorkHours = 'work_hours';
    case ExtendedHours = 'extended_hours';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Always => 'Always',
            self::WorkHours => 'Work hours',
            self::ExtendedHours => 'Extended hours',
            self::Custom => 'Custom',
        };
    }
}
