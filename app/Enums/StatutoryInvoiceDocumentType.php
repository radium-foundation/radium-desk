<?php

namespace App\Enums;

enum StatutoryInvoiceDocumentType: string
{
    case TaxInvoice = 'tax_invoice';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';

    public function label(): string
    {
        return match ($this) {
            self::TaxInvoice => 'Tax invoice',
            self::CreditNote => 'Credit note',
            self::DebitNote => 'Debit note',
        };
    }
}
