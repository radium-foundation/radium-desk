<?php

namespace App\Enums;

enum TeamAvailabilityStatus: string
{
    case Available = 'available';
    case Busy = 'busy';
    case Offline = 'offline';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Busy => 'Busy',
            self::Offline => 'Offline',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::Busy => 'warning',
            self::Offline => 'secondary',
        };
    }

    /**
     * @return list<self>
     */
    public static function liveCases(): array
    {
        return self::cases();
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
