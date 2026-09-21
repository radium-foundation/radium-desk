<?php

namespace App\Reports\CaMonthly;

/**
 * Authoritative payment evidence for cancelled statutory invoice inclusion.
 */
enum CaMonthlyReportCancelledPaymentEvidence: string
{
    case PaymentReference = 'payment_reference';
    case PaymentMethod = 'payment_method';
    case PaymentAllocation = 'payment_allocation';
    case InvoiceValueOnly = 'invoice_value_only';
    case None = 'none';

    public function includesCancelledInvoice(): bool
    {
        return match ($this) {
            self::PaymentReference,
            self::PaymentMethod,
            self::PaymentAllocation => true,
            self::InvoiceValueOnly,
            self::None => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PaymentReference => 'payment reference',
            self::PaymentMethod => 'payment method',
            self::PaymentAllocation => 'Service POS payment allocation',
            self::InvoiceValueOnly => 'invoice value only (ambiguous)',
            self::None => 'no payment evidence',
        };
    }
}
