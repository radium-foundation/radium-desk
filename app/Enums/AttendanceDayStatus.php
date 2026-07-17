<?php

namespace App\Enums;

enum AttendanceDayStatus: string
{
    case ScheduledOff = 'scheduled_off';
    case OnLeave = 'on_leave';
    case NotStarted = 'not_started';
    case OnTime = 'on_time';
    case Late = 'late';
    case Active = 'active';
    case Away = 'away';
    case Completed = 'completed';
    case Extra = 'extra';

    public function label(): string
    {
        return match ($this) {
            self::ScheduledOff => 'Off day',
            self::OnLeave => 'On leave',
            self::NotStarted => 'Not logged in',
            self::OnTime => 'On time',
            self::Late => 'Late',
            self::Active => 'Working',
            self::Away => 'Away',
            self::Completed => 'Day complete',
            self::Extra => 'Extra working',
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
