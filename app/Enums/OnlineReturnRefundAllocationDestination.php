<?php

namespace App\Enums;

/**
 * Finance-selected refund execution destination (allocation record only; no payout in this workflow).
 */
enum OnlineReturnRefundAllocationDestination: string
{
    case Wallet = 'wallet';
    case Cashfree = 'cashfree';
    case BankTransfer = 'bank_transfer';
    case Upi = 'upi';
    case Opm = 'opm';

    public function label(): string
    {
        return match ($this) {
            self::Wallet => 'Customer wallet',
            self::Cashfree => 'Cashfree (original gateway)',
            self::BankTransfer => 'Bank transfer (manual)',
            self::Upi => 'UPI (manual)',
            self::Opm => 'Original payment method (OPM)',
        };
    }

    /**
     * @return list<self>
     */
    public static function supported(): array
    {
        return self::cases();
    }
}
