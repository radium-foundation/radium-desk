<?php

namespace App\Enums;

enum RefundDeductionProfile: string
{
    case FullRefund = 'full_refund';
    case StandardCancellation = 'standard_cancellation';
    case EngineerVisit = 'engineer_visit';
    case Custom = 'custom';

    public function label(): string
    {
        return (string) (config('refunds.profiles.'.$this->value.'.label') ?? match ($this) {
            self::FullRefund => 'Full Refund',
            self::StandardCancellation => 'Standard Cancellation',
            self::EngineerVisit => 'Engineer Visit',
            self::Custom => 'Custom',
        });
    }
}
