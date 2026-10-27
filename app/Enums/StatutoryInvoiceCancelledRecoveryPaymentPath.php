<?php

namespace App\Enums;

enum StatutoryInvoiceCancelledRecoveryPaymentPath: string
{
    case NotCancelled = 'not_cancelled';
    case Unpaid = 'unpaid';
    case PaidDeskAllocations = 'paid_desk_allocations';
    case PaidLegacyInvoiceFields = 'paid_legacy_invoice_fields';
    case Ambiguous = 'ambiguous';

    public function label(): string
    {
        return match ($this) {
            self::NotCancelled => 'Not applicable',
            self::Unpaid => 'Unpaid (no Desk allocation evidence)',
            self::PaidDeskAllocations => 'Paid (Finance payment allocations on this invoice)',
            self::PaidLegacyInvoiceFields => 'Possible paid (legacy payment_method on invoice only — verify externally)',
            self::Ambiguous => 'Ambiguous — verify payment with Finance before any recovery action',
        };
    }
}
