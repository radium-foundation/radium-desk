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

    /**
     * Internal wallet credit — no external bank/payment-provider cash movement.
     */
    public function isWalletCredit(): bool
    {
        return $this === self::Wallet;
    }

    /**
     * External payment reversal (Cashfree, bank, UPI, or other OPM attestation).
     */
    public function isExternalPaymentReversal(): bool
    {
        return match ($this) {
            self::Cashfree, self::BankTransfer, self::Upi, self::Other => true,
            self::Wallet => false,
        };
    }
}
