<?php

namespace App\Enums;

enum InventoryTransferStatus: string
{
    case Draft = 'draft';
    case Dispatched = 'dispatched';
    case InTransit = 'in_transit';
    case Received = 'received';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Dispatched => 'Dispatched',
            self::InTransit => 'In transit',
            self::Received => 'Received',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }
}
