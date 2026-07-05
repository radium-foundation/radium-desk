<?php

namespace App\Enums;

enum TeamAvailabilityStatus: string
{
    case Available = 'available';
    case Busy = 'busy';
    case OnLeave = 'on_leave';
    case Offline = 'offline';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Busy => 'Busy',
            self::OnLeave => 'On Leave',
            self::Offline => 'Offline',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::Busy => 'warning',
            self::OnLeave => 'info',
            self::Offline => 'secondary',
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
