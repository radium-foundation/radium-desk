<?php

namespace App\Enums;

enum WorkCalendarDayStatus: string
{
    case Working = 'working';
    case LeaveApproved = 'leave_approved';
    case StartsLater = 'starts_later';
    case WeeklyOff = 'weekly_off';
    case Holiday = 'holiday';
    case OutsideHours = 'outside_hours';
    case Lunch = 'lunch';
    case NoSchedule = 'no_schedule';

    public function label(): string
    {
        return match ($this) {
            self::Working => 'Working',
            self::LeaveApproved => 'Leave approved',
            self::StartsLater => 'Starts later',
            self::WeeklyOff => 'Weekly off',
            self::Holiday => 'Holiday',
            self::OutsideHours => 'Outside hours',
            self::Lunch => 'At lunch',
            self::NoSchedule => 'No schedule',
        };
    }

    public function indicator(): string
    {
        return match ($this) {
            self::Working => '🟢',
            self::LeaveApproved => '🔴',
            self::StartsLater => '🟡',
            self::WeeklyOff => '⚪',
            self::Holiday => '🔴',
            self::OutsideHours => '⚪',
            self::Lunch => '🟡',
            self::NoSchedule => '⚪',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Working => 'success',
            self::LeaveApproved, self::Holiday => 'danger',
            self::StartsLater, self::Lunch => 'warning',
            self::WeeklyOff, self::OutsideHours, self::NoSchedule => 'secondary',
        };
    }
}
