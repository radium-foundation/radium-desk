<?php

namespace App\Enums;

enum StatutoryInvoicePaymentStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partial';
    case Paid = 'paid';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
            self::Refunded => 'Refunded',
            self::PartiallyRefunded => 'Partially refunded',
        };
    }

    public function pdfLabel(): string
    {
        return match ($this) {
            self::Unpaid => 'UNPAID',
            self::PartiallyPaid => 'Partially Paid',
            self::Paid => 'Paid',
            self::Refunded => 'Refunded',
            self::PartiallyRefunded => 'Partially refunded',
        };
    }

    public function isSettled(): bool
    {
        return $this === self::Paid;
    }
}
