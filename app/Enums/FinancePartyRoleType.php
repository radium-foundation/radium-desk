<?php

namespace App\Enums;

enum FinancePartyRoleType: string
{
    case Customer = 'customer';
    case Vendor = 'vendor';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Vendor => 'Vendor',
        };
    }
}
