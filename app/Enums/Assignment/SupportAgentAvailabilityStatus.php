<?php

namespace App\Enums\Assignment;

use App\Enums\TeamAvailabilityStatus;

enum SupportAgentAvailabilityStatus: string
{
    case Available = 'available';
    case Away = 'away';
    case Lunch = 'lunch';
    case Tea = 'tea';
    case Meeting = 'meeting';
    case Offline = 'offline';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Away => 'Away',
            self::Lunch => 'Lunch',
            self::Tea => 'Tea',
            self::Meeting => 'Meeting',
            self::Offline => 'Offline',
            self::Unavailable => 'Unavailable',
        };
    }

    public function isAssignableForSupport(): bool
    {
        return match ($this) {
            self::Available, self::Away, self::Lunch, self::Tea, self::Meeting => true,
            self::Offline, self::Unavailable => false,
        };
    }

    public static function fromTeamAvailability(TeamAvailabilityStatus $status): self
    {
        return match ($status) {
            TeamAvailabilityStatus::Available => self::Available,
            TeamAvailabilityStatus::Busy => self::Available,
            TeamAvailabilityStatus::Offline => self::Offline,
        };
    }
}
