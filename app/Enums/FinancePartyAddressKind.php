<?php

namespace App\Enums;

enum FinancePartyAddressKind: string
{
    case Registered = 'registered';
    case Billing = 'billing';
    case Shipping = 'shipping';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Registered',
            self::Billing => 'Billing',
            self::Shipping => 'Shipping',
            self::Other => 'Other',
        };
    }
}
