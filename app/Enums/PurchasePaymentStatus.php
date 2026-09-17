<?php

namespace App\Enums;

enum PurchasePaymentStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
        };
    }

    public static function fromAmounts(string $invoiceAmount, string $paidAmount): self
    {
        $invoice = (float) $invoiceAmount;
        $paid = (float) $paidAmount;

        if ($paid <= 0) {
            return self::Unpaid;
        }

        if ($paid + 0.009 < $invoice) {
            return self::PartiallyPaid;
        }

        return self::Paid;
    }
}
