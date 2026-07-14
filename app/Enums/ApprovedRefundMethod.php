<?php

namespace App\Enums;

enum ApprovedRefundMethod: string
{
    case Wallet = 'wallet';
    case Cashfree = 'cashfree';
    case BankTransfer = 'bank_transfer';
    case Upi = 'upi';
    case Other = 'other';

    public function label(): string
    {
        return (string) (config('refunds.refund_methods.'.$this->value) ?? match ($this) {
            self::Wallet => 'Wallet',
            self::Cashfree => 'Cashfree',
            self::BankTransfer => 'Bank Transfer',
            self::Upi => 'UPI',
            self::Other => 'Other',
        });
    }
}
