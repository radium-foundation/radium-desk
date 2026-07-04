<?php

namespace App\Enums;

enum CustomerIdentityType: string
{
    case CashfreeVerified = 'cashfree_verified';
    case Legacy = 'legacy';
    case NewContact = 'new_contact';

    public function label(): string
    {
        return match ($this) {
            self::CashfreeVerified => 'Cashfree Verified Customer',
            self::Legacy => 'Legacy Customer',
            self::NewContact => 'New Contact',
        };
    }
}
