<?php

namespace App\Enums;

enum InventoryTransferStatus: string
{
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }
}
