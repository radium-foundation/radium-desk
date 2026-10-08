<?php

namespace App\Enums;

enum InterBranchTransactionStatus: string
{
    case Issued = 'issued';
    case Dispatched = 'dispatched';
    case InTransit = 'in_transit';
    case Received = 'received';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Issued',
            self::Dispatched => 'Dispatched',
            self::InTransit => 'In transit',
            self::Received => 'Received',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * @return list<self>
     */
    public static function activeStockHolding(): array
    {
        return [
            self::Issued,
            self::Dispatched,
            self::InTransit,
            self::Received,
        ];
    }

    public function canDispatch(): bool
    {
        return $this === self::Issued;
    }

    public function canReceive(): bool
    {
        return in_array($this, [self::Dispatched, self::InTransit], true);
    }

    public function canCancel(): bool
    {
        return in_array($this, [self::Issued, self::Dispatched, self::InTransit], true);
    }
}
