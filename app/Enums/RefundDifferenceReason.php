<?php

namespace App\Enums;

enum RefundDifferenceReason: string
{
    case CancellationCharges = 'cancellation_charges';
    case EngineerVisit = 'engineer_visit';
    case PartialRefund = 'partial_refund';
    case Goodwill = 'goodwill';
    case Other = 'other';

    public function label(): string
    {
        return (string) (config('refunds.difference_reasons.'.$this->value) ?? match ($this) {
            self::CancellationCharges => 'Cancellation Charges',
            self::EngineerVisit => 'Engineer Visit',
            self::PartialRefund => 'Partial Refund',
            self::Goodwill => 'Goodwill',
            self::Other => 'Other',
        });
    }
}
