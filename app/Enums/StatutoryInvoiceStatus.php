<?php

namespace App\Enums;

enum StatutoryInvoiceStatus: string
{
    case Issued = 'issued';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Issued',
            self::Cancelled => 'Cancelled',
        };
    }
}
