<?php

namespace App\Enums;

enum PosPaymentIntentStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Abandoned = 'abandoned';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Abandoned => 'Abandoned',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
