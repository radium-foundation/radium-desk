<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\LedgerEntryStatus;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use App\Models\RefundRequest;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Blocks standalone Desk Central Wallet credits for wallet refunds that still have
 * independent spoke spendable value without a paired reconciliation migration.
 */
final class WalletRefundAuthoritativeCreditGuard
{
    public function __construct(
        private readonly WalletRefundAccountingDiscrepancyClassifier $classifier,
    ) {}

    public function hasPostedDeskCreditForReference(string $businessReference): bool
    {
        $reference = trim($businessReference);
        if ($reference === '') {
            return false;
        }

        return $this->hasPostedDeskCredit($reference);
    }

    public function assertMayPostStandaloneDeskCredit(RefundRequest $refund): void
    {
        $refund->loadMissing('order');
        $orderId = (int) ($refund->order_id ?? 0);
        if ($orderId <= 0) {
            return;
        }

        $reference = trim((string) $refund->reference_no);
        if ($reference === '') {
            return;
        }

        $migration = CentralWalletRefundMigration::query()
            ->where('refund_id', (int) $refund->id)
            ->first();

        if ($migration !== null && $migration->status === RefundMigrationStatus::Reconciled) {
            if (RefundMigrationFinancialEvidence::isReconciledWithEvidence($migration)) {
                return;
            }
        }

        if ($migration !== null
            && $migration->status !== RefundMigrationStatus::Reconciled
            && $this->hasPostedDeskCredit($reference)) {
            throw new InvalidArgumentException('wallet_refund_requires_paired_reconciliation');
        }

        $gaps = $this->classifier->authorityGapsForDeskOrder($orderId);
        foreach ($gaps as $gap) {
            if (($gap['reference_no'] ?? '') !== $reference) {
                continue;
            }

            throw new InvalidArgumentException('wallet_refund_requires_paired_reconciliation');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function authorityGapsForRefund(RefundRequest $refund): array
    {
        $refund->loadMissing('order');
        $orderId = (int) ($refund->order_id ?? 0);
        if ($orderId <= 0) {
            return [];
        }

        return $this->classifier->authorityGapsForDeskOrder($orderId);
    }

    private function hasPostedDeskCredit(string $businessReference): bool
    {
        return DB::table('central_wallet_ledger_entries')
            ->where('business_reference', $businessReference)
            ->where('entry_type', 'credit')
            ->where('status', LedgerEntryStatus::Posted->value)
            ->exists();
    }
}
