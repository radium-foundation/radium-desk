<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\LedgerEntryStatus;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Domain\Enums\WalletRefundAccountingClass;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only classifier for wallet refund accounting alignment on Desk.
 * Identifies refunds that likely relied on legacy spoke spendable credits without a Desk CW ledger credit.
 */
final class WalletRefundAccountingDiscrepancyClassifier
{
    private const TERMINAL_STATUSES = ['closed', 'completed'];

    public function __construct(
        private readonly ReconciledHistoricalRefundFilter $reconciledFilter,
    ) {}

    /**
     * @return array{
     *     generated_at: string,
     *     summary: array<string, int>,
     *     rows: list<array<string, mixed>>,
     * }
     */
    /**
     * Desk Customer 360 / order context: wallet refunds that lack posted CW credits (target-policy gap).
     *
     * @return list<array{
     *     refund_id: int,
     *     reference_no: string,
     *     refund_amount: string,
     *     classification: string,
     *     policy_violation: bool,
     * }>
     */
    public function authorityGapsForDeskOrder(int $deskOrderId): array
    {
        if ($deskOrderId <= 0) {
            return [];
        }

        $refunds = DB::table('refund_requests')
            ->where('order_id', $deskOrderId)
            ->where('approved_refund_method', 'wallet')
            ->whereIn('status', self::TERMINAL_STATUSES)
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->get([
                'id',
                'reference_no',
                'order_id',
                'status',
                'refund_amount',
                'amount',
                'execution_reference_no',
                'execution_transaction_id',
                'refund_transaction_id',
                'revoked_at',
                'updated_at',
            ]);

        if ($refunds->isEmpty()) {
            return [];
        }

        $refNos = $refunds->pluck('reference_no')->filter()->unique()->values()->all();
        $ledgerByRef = $this->ledgerCreditsByBusinessReference($refNos);

        $migrationByRefundId = CentralWalletRefundMigration::query()
            ->whereIn('refund_id', $refunds->pluck('id')->map(static fn ($id): int => (int) $id)->all())
            ->get()
            ->keyBy('refund_id');

        $notices = [];

        foreach ($refunds as $refund) {
            $class = $this->classifyRow($refund, $ledgerByRef, $migrationByRefundId);
            if (! $this->isPolicyViolation($class)) {
                continue;
            }

            $notices[] = [
                'refund_id' => (int) $refund->id,
                'reference_no' => (string) $refund->reference_no,
                'refund_amount' => (string) ($refund->refund_amount ?? $refund->amount ?? '0'),
                'classification' => $class->value,
                'policy_violation' => true,
            ];
        }

        return $notices;
    }

    public function buildReport(?int $limit = null, ?int $afterRefundId = null): array
    {
        $query = DB::table('refund_requests')
            ->where('approved_refund_method', 'wallet')
            ->whereIn('status', self::TERMINAL_STATUSES)
            ->orderBy('id');

        if ($afterRefundId !== null && $afterRefundId > 0) {
            $query->where('id', '>', $afterRefundId);
        }

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $refunds = $query->get([
            'id',
            'reference_no',
            'order_id',
            'status',
            'refund_amount',
            'amount',
            'execution_reference_no',
            'execution_transaction_id',
            'refund_transaction_id',
            'revoked_at',
            'updated_at',
        ]);

        $refNos = $refunds->pluck('reference_no')->filter()->unique()->values()->all();
        $ledgerByRef = $this->ledgerCreditsByBusinessReference($refNos);

        $migrationByRefundId = CentralWalletRefundMigration::query()
            ->whereIn('refund_id', $refunds->pluck('id')->map(static fn ($id): int => (int) $id)->all())
            ->get()
            ->keyBy('refund_id');

        $rows = [];
        $summary = [];

        foreach ($refunds as $refund) {
            $class = $this->classifyRow($refund, $ledgerByRef, $migrationByRefundId);
            $summary[$class->value] = ($summary[$class->value] ?? 0) + 1;

            $rows[] = [
                'refund_id' => (int) $refund->id,
                'reference_no' => (string) $refund->reference_no,
                'order_id' => (int) $refund->order_id,
                'refund_amount' => (string) ($refund->refund_amount ?? $refund->amount ?? '0'),
                'status' => (string) $refund->status,
                'execution_transaction_id' => $this->nullableString($refund->execution_transaction_id),
                'execution_reference_no' => $this->nullableString($refund->execution_reference_no),
                'desk_ledger_credit_count' => $ledgerByRef[(string) $refund->reference_no]['count'] ?? 0,
                'desk_ledger_credit_sum' => $ledgerByRef[(string) $refund->reference_no]['sum'] ?? '0.00',
                'migration_status' => isset($migrationByRefundId[(int) $refund->id])
                    ? (string) $migrationByRefundId[(int) $refund->id]->status->value
                    : null,
                'classification' => $class->value,
                'policy_violation' => $this->isPolicyViolation($class),
                'updated_at' => (string) $refund->updated_at,
            ];
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'summary' => $summary,
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<string>  $referenceNos
     * @return array<string, array{count: int, sum: string}>
     */
    private function ledgerCreditsByBusinessReference(array $referenceNos): array
    {
        if ($referenceNos === []) {
            return [];
        }

        $out = [];
        $entries = DB::table('central_wallet_ledger_entries')
            ->whereIn('business_reference', $referenceNos)
            ->where('entry_type', 'credit')
            ->where('status', LedgerEntryStatus::Posted->value)
            ->get(['business_reference', 'amount']);

        foreach ($entries as $entry) {
            $ref = (string) $entry->business_reference;
            if (! isset($out[$ref])) {
                $out[$ref] = ['count' => 0, 'sum' => '0.00'];
            }
            $out[$ref]['count']++;
            $out[$ref]['sum'] = bcadd($out[$ref]['sum'], (string) $entry->amount, 2);
        }

        return $out;
    }

    /**
     * @param  array<string, array{count: int, sum: string}>  $ledgerByRef
     * @param  Collection<int, CentralWalletRefundMigration>  $migrationByRefundId
     */
    private function classifyRow(
        object $refund,
        array $ledgerByRef,
        Collection $migrationByRefundId,
    ): WalletRefundAccountingClass {
        if ($refund->revoked_at !== null) {
            return WalletRefundAccountingClass::Revoked;
        }

        $refundId = (int) $refund->id;
        $refNo = (string) $refund->reference_no;
        $migration = $migrationByRefundId->get($refundId);

        if ($migration !== null && $migration->status === RefundMigrationStatus::Reconciled) {
            return WalletRefundAccountingClass::MigrationReconciled;
        }

        if ($this->reconciledFilter->isReconciled($refundId)) {
            return WalletRefundAccountingClass::MigrationReconciled;
        }

        $ledger = $ledgerByRef[$refNo] ?? ['count' => 0, 'sum' => '0.00'];
        $hasLedger = $ledger['count'] > 0;
        $hasSpokeExecution = $this->hasSpokeExecutionEvidence($refund);

        if ($hasLedger && $hasSpokeExecution) {
            return WalletRefundAccountingClass::AuthoritativeDeskLedger;
        }

        if ($hasLedger && ! $hasSpokeExecution) {
            return WalletRefundAccountingClass::DeskLedgerWithoutSpokeExecution;
        }

        if (! $hasLedger && $hasSpokeExecution) {
            return WalletRefundAccountingClass::SpokeExecutionWithoutDeskLedger;
        }

        return WalletRefundAccountingClass::NoExecutionEvidence;
    }

    private function hasSpokeExecutionEvidence(object $refund): bool
    {
        foreach (['execution_transaction_id', 'execution_reference_no', 'refund_transaction_id'] as $field) {
            $value = trim((string) ($refund->{$field} ?? ''));
            if ($value !== '' && $value !== '0') {
                return true;
            }
        }

        return false;
    }

    private function isPolicyViolation(WalletRefundAccountingClass $class): bool
    {
        return $class === WalletRefundAccountingClass::SpokeExecutionWithoutDeskLedger;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
