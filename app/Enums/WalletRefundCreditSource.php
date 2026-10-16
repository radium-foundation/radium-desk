<?php

namespace App\Enums;

enum WalletRefundCreditSource: string
{
    case LegacyLocal = 'legacy_local';
    case CentralWallet = 'central_wallet';

    public function label(): string
    {
        return match ($this) {
            self::LegacyLocal => 'Legacy local wallet',
            self::CentralWallet => 'Desk Central Wallet',
        };
    }
}
