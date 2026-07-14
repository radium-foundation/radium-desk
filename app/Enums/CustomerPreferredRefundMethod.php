<?php

namespace App\Enums;

enum CustomerPreferredRefundMethod: string
{
    case Wallet = 'wallet';
    case Opm = 'opm';

    public function label(): string
    {
        return (string) (config('refunds.customer_preferred_methods.'.$this->value) ?? match ($this) {
            self::Wallet => 'Wallet',
            self::Opm => 'OPM (Original Payment Method)',
        });
    }
}
