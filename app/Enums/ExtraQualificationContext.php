<?php

namespace App\Enums;

/**
 * Calendar / leave context used by Extra Qualification (Rule Book §9 rows).
 */
enum ExtraQualificationContext: string
{
    case WorkingDay = 'working_day';
    case WeeklyOff = 'weekly_off';
    case Holiday = 'holiday';
    case Leave = 'leave';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::WorkingDay => 'Working Day',
            self::WeeklyOff => 'Weekly Off',
            self::Holiday => 'Holiday',
            self::Leave => 'Leave',
            self::Unknown => 'Unknown',
        };
    }
}
