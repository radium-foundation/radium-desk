<?php

namespace App\Support\Refunds;

use Illuminate\Validation\ValidationException;

/**
 * Fail-closed gate for new RadiumBox legacy spendable credits when Desk policy requires a central ledger.
 */
final class RadiumBoxLegacyWalletRefundExecutionPolicy
{
    public static function assertLegacySpendableCreditAllowed(): void
    {
        if (! self::blocksLegacySpendableCredit()) {
            return;
        }

        throw ValidationException::withMessages([
            'refund' => 'RadiumBox wallet refunds must post Desk Central Wallet ledger credit before any local spendable credit. '
                .'Enable desk-first wallet refunds for radiumbox.com after spoke audit contract verification, '
                .'or use an approved pilot reconciliation manifest for historical legacy rows.',
        ]);
    }

    public static function blocksLegacySpendableCredit(): bool
    {
        return filter_var(
            config('central_wallet.wallet_refund.block_radiumbox_legacy_spendable_without_desk_ledger', false),
            FILTER_VALIDATE_BOOLEAN,
        );
    }
}
