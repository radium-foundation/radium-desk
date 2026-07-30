<?php

namespace App\Enums;

enum AttendanceMatrixCellKind: string
{
    case Present = 'present';
    case Late = 'late';
    case Absent = 'absent';
    case Leave = 'leave';
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
            self::Present => 'Present',
            self::Late => 'Late',
            self::Absent => 'Absent',
            self::Leave => 'Leave',
            self::Holiday => 'Holiday',
            self::WeeklyOff => 'Off',
            self::Extra => 'Extra',
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
            self::Holiday => 'secondary',
            self::WeeklyOff => 'secondary',
            self::Extra => 'primary',
            self::Future, self::Empty => 'muted',
        };
    }

    public function isInteractive(): bool
    {
        return ! in_array($this, [self::Future, self::Empty], true);
    }
}
