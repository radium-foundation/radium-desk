<?php

namespace App\Support\Refunds;

use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundRevocationAttemptStatus;
use App\Enums\RefundStatus;
use App\Models\RefundRequest;
use App\Models\RefundRevocationAttempt;

/**
 * Supported operator-facing recovery signals for desk-first wallet refunds (flag on).
 */
final class DeskFirstWalletRefundRecoveryInspector
{
    public function completionRecoveryNotice(RefundRequest $refund): ?string
    {
        if (! $this->appliesToRefund($refund)) {
            return null;
        }

        if ($refund->status !== RefundStatus::PendingExecution) {
            return null;
        }

        $credit = $this->committedDeskCredit($refund);
        if ($credit === null) {
            return null;
        }

        return 'Central Wallet credit is already posted ('.$this->walletReference((int) $credit->id).'). '
            .'Spoke audit is not confirmed yet. Use Complete again to retry audit acknowledgement only—do not create a manual wallet credit.';
    }

    public function revocationRecoveryNotice(RefundRequest $refund): ?string
    {
        if (! ($refund->status?->isEligibleForRevoke() ?? false)) {
            return null;
        }

        if (! DeskFirstWalletRefundSupport::hasPersistedDeskFirstCentralWalletExecution($refund)) {
            return null;
        }

        $debit = $this->committedRevokeDebit($refund);
        if ($debit === null) {
            return null;
        }

        $attempt = $this->latestRevocationAttempt($refund);
        $ledgerRef = $this->walletReference((int) $debit->id);

        if ($attempt?->status === RefundRevocationAttemptStatus::WalletReversed) {
            return 'Central Wallet reversal debit is already posted ('.$ledgerRef.'). '
                .'Submit Revoke again to finish spoke audit retirement and restore service—do not post another Central Wallet debit.';
        }

        if ($attempt?->status === RefundRevocationAttemptStatus::Failed
            || $attempt?->status === RefundRevocationAttemptStatus::Pending) {
            return 'Central Wallet reversal debit is already posted ('.$ledgerRef.'). '
                .'Submit Revoke again to retry audit retirement only—do not post another Central Wallet debit.';
        }

        return 'Central Wallet reversal debit is already posted ('.$ledgerRef.'). '
            .'Submit Revoke again to finish this desk-first revocation.';
    }

    public function hasCommittedDeskCreditAwaitingSpokeAudit(RefundRequest $refund): bool
    {
        return $this->completionRecoveryNotice($refund) !== null;
    }

    private function appliesToRefund(RefundRequest $refund): bool
    {
        if (! DeskFirstWalletRefundSupport::isDeskFirstEnabled()) {
            return false;
        }

        return $refund->approved_refund_method === ApprovedRefundMethod::Wallet;
    }

    private function committedDeskCredit(RefundRequest $refund): ?CentralWalletLedgerEntry
    {
        $reference = trim((string) $refund->reference_no);
        if ($reference === '') {
            return null;
        }

        return CentralWalletLedgerEntry::query()
            ->where('business_reference', $reference)
            ->where('entry_type', LedgerEntryType::Credit)
            ->first();
    }

    private function committedRevokeDebit(RefundRequest $refund): ?CentralWalletLedgerEntry
    {
        $sourceReference = DeskFirstWalletRefundSupport::revokeLedgerSourceReference((int) $refund->id);

        return CentralWalletLedgerEntry::query()
            ->where('source_reference', $sourceReference)
            ->where('entry_type', LedgerEntryType::Debit)
            ->first();
    }

    private function latestRevocationAttempt(RefundRequest $refund): ?RefundRevocationAttempt
    {
        return RefundRevocationAttempt::query()
            ->where('refund_request_id', $refund->id)
            ->orderByDesc('id')
            ->first();
    }

    private function walletReference(int $ledgerEntryId): string
    {
        return 'CW:'.$ledgerEntryId;
    }
}
