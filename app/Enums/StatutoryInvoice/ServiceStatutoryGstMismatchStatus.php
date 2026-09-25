<?php

namespace App\Enums\StatutoryInvoice;

enum ServiceStatutoryGstMismatchStatus: string
{
    case GstMismatch = 'gst_mismatch';
    case CustomerContacted = 'customer_contacted';
    case AwaitingCustomer = 'awaiting_customer';
    case CustomerDataReceived = 'customer_data_received';
    case ReadyForB2b = 'ready_for_b2b';
    case B2bInvoiceIssued = 'b2b_invoice_issued';
    case B2cFallbackPending = 'b2c_fallback_pending';
    case B2cInvoiceIssued = 'b2c_invoice_issued';

    public function label(): string
    {
        return match ($this) {
            self::GstMismatch => 'GST Mismatch — Statutory Invoice Pending',
            self::CustomerContacted => 'Customer Contacted',
            self::AwaitingCustomer => 'Awaiting Customer',
            self::CustomerDataReceived => 'Customer Data Received',
            self::ReadyForB2b => 'Ready for B2B Invoice',
            self::B2bInvoiceIssued => 'B2B Invoice Issued',
            self::B2cFallbackPending => 'B2C Fallback Pending',
            self::B2cInvoiceIssued => 'B2C — 72h GST Verification Fallback',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::B2bInvoiceIssued, self::B2cInvoiceIssued], true);
    }

    public function blocksMintRetry(): bool
    {
        return in_array($this, [
            self::GstMismatch,
            self::CustomerContacted,
            self::AwaitingCustomer,
            self::CustomerDataReceived,
            self::B2cFallbackPending,
        ], true);
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => ! $status->isTerminal()),
        ));
    }
}
