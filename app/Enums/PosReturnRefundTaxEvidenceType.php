<?php

namespace App\Enums;

enum PosReturnRefundTaxEvidenceType: string
{
    case Cancellation = 'cancellation';
    case CreditNote = 'credit_note';

    public function label(): string
    {
        return match ($this) {
            self::Cancellation => 'Statutory cancellation recorded',
            self::CreditNote => 'Linked credit note',
        };
    }
}
