<?php

namespace App\Enums;

/**
 * CA/statutory treatment decisions for customer-requested serial exchange on issued invoices.
 * Does not perform invoice mutations; execution gate only.
 */
enum PosCustomerRequestedSerialExchangeStatutoryTreatment: string
{
    case SerialOnlyNoInvoiceChange = 'serial_only_no_invoice_change';
    case RequiresInvoiceCancellation = 'requires_invoice_cancellation';
    case RequiresCreditNote = 'requires_credit_note';
    case RequiresInvoiceReissue = 'requires_invoice_reissue';

    public function permitsSerialOnlyExecution(): bool
    {
        return $this === self::SerialOnlyNoInvoiceChange;
    }

    public function label(): string
    {
        return match ($this) {
            self::SerialOnlyNoInvoiceChange => 'Serial-only exchange; preserve issued invoice',
            self::RequiresInvoiceCancellation => 'Statutory invoice cancellation required (blocked for serial-only service)',
            self::RequiresCreditNote => 'Credit note required (blocked for serial-only service)',
            self::RequiresInvoiceReissue => 'Invoice reissue required (blocked for serial-only service)',
        };
    }
}
