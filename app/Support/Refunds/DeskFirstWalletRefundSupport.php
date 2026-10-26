<?php

namespace App\Support\Refunds;

use App\Models\RefundRequest;

final class DeskFirstWalletRefundSupport
{
    public static function isDeskFirstEnabled(): bool
    {
        return (bool) config('central_wallet.wallet_refund.desk_first_enabled', false);
    }

    public static function isDeskAuthoritativeEnabled(): bool
    {
        return filter_var(
            config('central_wallet.wallet_refund.desk_authoritative_enabled', true),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /**
     * Wallet refund completion requires posted Desk Central Wallet ledger evidence.
     */
    public static function requiresAuthoritativeDeskLedger(): bool
    {
        return self::isDeskAuthoritativeEnabled();
    }

    public static function completionGateApplies(): bool
    {
        return self::isDeskFirstEnabled() || self::requiresAuthoritativeDeskLedger();
    }

    /**
     * Persisted Desk-first completion marker (independent of current feature flag).
     */
    public static function hasPersistedDeskFirstCentralWalletExecution(RefundRequest $refund): bool
    {
        $reference = trim((string) ($refund->execution_reference_no ?? ''));
        if ($reference === '') {
            return false;
        }

        return preg_match('/^CW:[1-9]\d*$/', $reference) === 1;
    }

    /**
     * @alias Use for revoke routing and post-completion desk-first semantics.
     */
    public static function isDeskFirstCompletedExecution(RefundRequest $refund): bool
    {
        return self::hasPersistedDeskFirstCentralWalletExecution($refund);
    }

    public static function deskLedgerCreditIdFromExecution(RefundRequest $refund): ?int
    {
        $txn = trim((string) ($refund->execution_transaction_id ?? ''));
        if ($txn !== '' && preg_match('/^(?:0|[1-9]\d*)$/', $txn) === 1) {
            return (int) $txn;
        }

        $reference = trim((string) ($refund->execution_reference_no ?? ''));
        if (preg_match('/^CW:([1-9]\d*)$/', $reference, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    public static function revokeLedgerSourceReference(int $refundId): string
    {
        return 'refund-revoke:refund_requests:'.$refundId;
    }
}
