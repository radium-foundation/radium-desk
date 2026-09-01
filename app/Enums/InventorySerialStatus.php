<?php

namespace App\Enums;

enum InventorySerialStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case InTransit = 'in_transit';
    case Sold = 'sold';
    case Damaged = 'damaged';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Reserved => 'Reserved',
            self::InTransit => 'In transit',
            self::Sold => 'Sold',
            self::Damaged => 'Damaged',
            self::Returned => 'Returned',
        };
    }

    public function isAssignable(): bool
    {
        return $this === self::Available;
    }
}
