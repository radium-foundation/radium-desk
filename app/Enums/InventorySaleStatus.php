<?php

namespace App\Enums;

enum InventorySaleStatus: string
{
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Returned => 'Returned',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Completed;
    }
}
