<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;

final class ReconciledHistoricalRefundFilter
{
    /** @var list<int>|null */
    private ?array $reconciledRefundIds = null;

    public function isReconciled(int $refundId): bool
    {
        return in_array($refundId, $this->reconciledRefundIds(), true);
    }

    public function isProtectedFromDisplay(int $refundId): bool
    {
        $protected = config('central_wallet.historical_wallet_visibility.protected_refund_ids', [300]);

        return in_array($refundId, $protected, true);
    }

    /**
     * @return list<int>
     */
    public function reconciledRefundIds(): array
    {
        if ($this->reconciledRefundIds === null) {
            $this->reconciledRefundIds = CentralWalletRefundMigration::query()
                ->where('status', RefundMigrationStatus::Reconciled)
                ->pluck('refund_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        return $this->reconciledRefundIds;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function sumDisplayableAmounts(array $rows, ?string $amountField = null): string
    {
        $total = '0.00';

        foreach ($rows as $row) {
            $refundId = (int) ($row['refund_id'] ?? 0);
            if ($refundId > 0 && ($this->isReconciled($refundId) || $this->isProtectedFromDisplay($refundId))) {
                continue;
            }

            $amount = $amountField !== null
                ? (string) ($row[$amountField] ?? '0')
                : (string) ($row['current_source_spendable_balance'] ?? $row['refund_amount'] ?? $row['amount'] ?? '0');

            $total = bcadd($total, $amount, 2);
        }

        return $total;
    }

    /**
     * @param  list<int>  $refundIds
     */
    public function sumDisplayableFromRefundIds(array $refundIds, array $amountsByRefundId): string
    {
        $total = '0.00';

        foreach ($refundIds as $refundId) {
            $refundId = (int) $refundId;
            if ($this->isReconciled($refundId) || $this->isProtectedFromDisplay($refundId)) {
                continue;
            }

            $amount = (string) ($amountsByRefundId[$refundId] ?? '0');
            $total = bcadd($total, $amount, 2);
        }

        return $total;
    }
}
