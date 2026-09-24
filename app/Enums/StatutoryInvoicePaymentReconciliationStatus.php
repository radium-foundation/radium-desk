<?php

namespace App\Enums;

enum StatutoryInvoicePaymentReconciliationStatus: string
{
    case Required = 'required';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Required => 'Required',
            self::Completed => 'Completed',
        };
    }
}
