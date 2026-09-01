<?php

namespace App\Enums;

enum InventoryReservationStatus: string
{
    case Active = 'active';
    case Released = 'released';
    case Consumed = 'consumed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Released => 'Released',
            self::Consumed => 'Consumed',
        };
    }
}
