<?php

namespace App\Enums;

enum AttendanceMatrixCellKind: string
{
    case Present = 'present';
    case Late = 'late';
    case Absent = 'absent';
    case Leave = 'leave';
    case HalfDay = 'half_day';
    case Holiday = 'holiday';
    case WeeklyOff = 'weekly_off';
    case Extra = 'extra';
    case Future = 'future';
    case Empty = 'empty';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Late => 'Late',
            self::Absent => 'Absent',
            self::Leave => 'Leave',
            self::HalfDay => 'Half Day',
            self::Holiday => 'Holiday',
            self::WeeklyOff => 'Weekly off',
            self::Extra => 'Extra working',
            self::Future => 'Upcoming',
            self::Empty => 'No data',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Present => 'P',
            self::Late => 'L',
            self::Absent => 'A',
            self::Leave => 'V',
            self::HalfDay => 'H',
            self::Holiday => 'N',
            self::WeeklyOff => 'W',
            self::Extra => 'E',
            self::Future => '—',
            self::Empty => '—',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Present => 'success',
            self::Late => 'warning',
            self::Absent => 'danger',
            self::Leave => 'info',
            self::HalfDay => 'half',
            self::Holiday => 'holiday',
            self::WeeklyOff => 'secondary',
            self::Extra => 'primary',
            self::Future, self::Empty => 'muted',
        };
    }

    /**
     * Payroll day fraction foundation. Half Day is always 0.5 when
     * the matrix cell comes from an approved half-day leave request.
     */
    public function payableDayFraction(): float
    {
        return match ($this) {
            self::Present, self::Late => 1.0,
            self::HalfDay => 0.5,
            default => 0.0,
        };
    }

    public function isInteractive(): bool
    {
        return ! in_array($this, [self::Future, self::Empty], true);
    }
}
