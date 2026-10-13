<?php

namespace App\Support\Refunds;

use App\Services\Refunds\WalletRefundDestinationResolver;
use Illuminate\Validation\ValidationException;

/**
 * Fail-closed gate: Desk-first must not call a spoke wallet-refund API that still
 * posts spendable balance unless that spoke implements the desk-authoritative audit contract
 * (central_wallet_ledger_entry_id / non-spendable audit row).
 */
final class DeskFirstWalletRefundSpokePolicy
{
    /**
     * @return list<string> site owner keys from WalletRefundDestinationResolver constants
     */
    public static function allowedSpokeOwners(): array
    {
        $raw = trim((string) config('central_wallet.wallet_refund.desk_first_spoke_allowlist', 'rdservice.in'));
        if ($raw === '') {
            return [];
        }

        $owners = array_values(array_filter(array_map(
            static fn (string $part): string => strtolower(trim($part)),
            explode(',', $raw),
        )));

        return array_values(array_unique($owners));
    }

    public static function isSpokeAllowedForDeskFirst(?string $orderId, WalletRefundDestinationResolver $destinations): bool
    {
        $owner = $destinations->owner($orderId);
        if ($owner === null) {
            return false;
        }

        return in_array(strtolower($owner), self::allowedSpokeOwners(), true);
    }

    public static function assertSpokeAllowedForDeskFirst(
        ?string $orderId,
        WalletRefundDestinationResolver $destinations,
    ): void {
        if (self::isSpokeAllowedForDeskFirst($orderId, $destinations)) {
            return;
        }

        $owner = $destinations->owner($orderId) ?? 'unknown';

        throw ValidationException::withMessages([
            'refund' => 'Desk-first Central Wallet refunds are not enabled for '.$owner.' orders until the spoke audit contract is deployed. '
                .'Use legacy wallet execution or extend CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_SPOKES after spoke verification.',
        ]);
    }
}
