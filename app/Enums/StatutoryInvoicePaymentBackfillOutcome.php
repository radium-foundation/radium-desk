<?php

namespace App\Enums;

enum StatutoryInvoicePaymentBackfillOutcome: string
{
    case Unpaid = 'unpaid';
    case VerifiedPayment = 'verified_payment';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::VerifiedPayment => 'Record verified payment',
            self::PartiallyPaid => 'Partially Paid',
            self::Paid => 'Paid',
        };
    }

    /**
     * @return list<self>
     */
    public static function submissionCases(): array
    {
        return [
            self::Unpaid,
            self::VerifiedPayment,
        ];
    }
}
