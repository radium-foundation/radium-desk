<?php

namespace App\Enums;

enum ServiceOrderPaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::Partial => 'Partially paid',
            self::Paid => 'Paid',
        };
    }
}
