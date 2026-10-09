<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletBalanceMigration;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RefundMigrationFalseReconcileRepairService
{
    public function __construct(
        private readonly RefundMigrationStateMachine $stateMachine,
        private readonly AuditEventRecorder $auditEvents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function repair(int $refundId, string $correlationId, string $actorId): array
    {
        $this->assertAllowlistedRefundId($refundId);

        return DB::transaction(function () use ($refundId, $correlationId, $actorId): array {
            $migration = CentralWalletRefundMigration::query()
                ->where('refund_id', $refundId)
                ->lockForUpdate()
                ->first();

            if ($migration === null) {
                throw new InvalidArgumentException('refund_migration_not_found');
            }

            if ($migration->status !== RefundMigrationStatus::Reconciled) {
                throw new InvalidArgumentException('refund_migration_not_false_reconcile_candidate');
            }

            if (RefundMigrationFinancialEvidence::isReconciledWithEvidence($migration)) {
                throw new InvalidArgumentException('refund_migration_has_financial_evidence');
            }

            $ledgerCount = CentralWalletLedgerEntry::query()
                ->where('central_wallet_id', $migration->cwid)
                ->where('business_reference', $migration->refund_reference)
                ->count();

            if ($ledgerCount > 0) {
                throw new InvalidArgumentException('refund_migration_ledger_evidence_exists');
            }

            $balanceMigration = null;
            $operationId = trim((string) ($migration->balance_migration_operation_id ?? ''));
            if ($operationId !== '') {
                $balanceMigration = CentralWalletBalanceMigration::query()
                    ->where('migration_operation_id', $operationId)
                    ->first();
            }

            if ($balanceMigration instanceof CentralWalletBalanceMigration
                && BalanceMigrationFinancialEvidence::isReconciledWithEvidence($balanceMigration)) {
                throw new InvalidArgumentException('balance_migration_has_financial_evidence');
            }

            $fromStatus = $migration->status;
            $this->stateMachine->assertCanTransition($fromStatus, RefundMigrationStatus::ReconciliationRequired);

            $balanceFailure = $balanceMigration?->failure_code;
            $metadata = $migration->metadata ?? [];
            $metadata['false_reconcile_repair'] = [
                'repaired_at' => now()->toIso8601String(),
                'correlation_id' => $correlationId,
                'balance_migration_operation_id' => $operationId !== '' ? $operationId : null,
                'balance_migration_status' => $balanceMigration?->status->value,
                'balance_migration_failure_code' => $balanceFailure,
                'prior_error_code' => $migration->error_code,
            ];

            $migration->fill([
                'status' => RefundMigrationStatus::ReconciliationRequired,
                'executed_at' => null,
                'error_code' => $balanceFailure !== null && $balanceFailure !== ''
                    ? (string) $balanceFailure
                    : 'false_reconcile_without_financial_evidence',
                'error_message' => json_encode([
                    'repair' => 'refund_migration_false_reconcile',
                    'balance_migration_operation_id' => $operationId !== '' ? $operationId : null,
                    'balance_migration_status' => $balanceMigration?->status->value,
                    'balance_migration_failure_code' => $balanceFailure,
                ], JSON_THROW_ON_ERROR),
                'metadata' => $metadata,
            ]);
            $migration->save();

            $this->auditEvents->record(
                eventType: 'refund_migration.false_reconcile_repaired',
                centralWalletId: $migration->cwid,
                actorType: AuditActorType::Service,
                actorId: $actorId,
                correlationId: $correlationId,
                payload: [
                    'refund_id' => $migration->refund_id,
                    'refund_reference' => $migration->refund_reference,
                    'from_status' => $fromStatus->value,
                    'to_status' => RefundMigrationStatus::ReconciliationRequired->value,
                    'balance_migration_operation_id' => $operationId !== '' ? $operationId : null,
                    'balance_migration_status' => $balanceMigration?->status->value,
                ],
            );

            return [
                'refund_id' => $migration->refund_id,
                'refund_reference' => $migration->refund_reference,
                'status' => $migration->status->value,
                'balance_migration_operation_id' => $migration->balance_migration_operation_id,
                'destination_ledger_entry_id' => $migration->destination_ledger_entry_id,
                'source_debit_reference' => $migration->source_debit_reference,
            ];
        });
    }

    private function assertAllowlistedRefundId(int $refundId): void
    {
        $allowed = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) config('central_wallet.pilot_refund_migration.allowed_refund_ids', '387')),
        )));

        if ($allowed !== [] && ! in_array($refundId, $allowed, true)) {
            throw new InvalidArgumentException('pilot_refund_id_not_allowlisted');
        }
    }
}
