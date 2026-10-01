<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\E2HistoricalSettlementClassification;
use App\CentralWallet\Domain\E2HistoricalSettlementIdempotencyKey;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class E2HistoricalManualRefundSettlementService
{
    public function __construct(
        private readonly RefundMigrationStateMachine $stateMachine,
        private readonly LedgerService $ledger,
        private readonly AuditEventRecorder $auditEvents,
    ) {}

    /**
     * @return array{status: string, ledger_entry_id?: int, idempotent_replay?: bool}
     */
    public function execute(
        CentralWalletRefundMigration $migration,
        string $ownerApprovalRef,
        string $correlationId,
        string $actorId,
    ): array {
        if ($migration->cwid === null) {
            throw new InvalidArgumentException('missing_cwid');
        }

        if ($migration->desk_customer_id === null) {
            throw new InvalidArgumentException('missing_desk_customer_id');
        }

        if ($migration->status === RefundMigrationStatus::Reconciled) {
            return [
                'status' => $migration->status->value,
                'ledger_entry_id' => $migration->destination_ledger_entry_id,
                'idempotent_replay' => true,
            ];
        }

        if ($migration->destination_ledger_entry_id !== null) {
            return $this->finalizeReconciled($migration);
        }

        $existingCredit = CentralWalletLedgerEntry::query()
            ->where('source_system', 'radium-desk')
            ->where('source_reference', $migration->source_reference)
            ->where('entry_type', LedgerEntryType::Credit->value)
            ->first();

        if ($existingCredit !== null) {
            if ($existingCredit->central_wallet_id !== $migration->cwid) {
                throw new InvalidArgumentException('duplicate_credit_wrong_wallet');
            }

            $migration = $this->transition($migration, RefundMigrationStatus::CwCredited, [
                'destination_ledger_entry_id' => $existingCredit->id,
            ]);

            return $this->finalizeReconciled($migration);
        }

        $migration = $this->transition($migration, RefundMigrationStatus::CwCreditPending);

        try {
            $migration = DB::transaction(function () use ($migration, $correlationId, $ownerApprovalRef, $actorId): CentralWalletRefundMigration {
                $locked = CentralWalletRefundMigration::query()
                    ->where('id', $migration->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->destination_ledger_entry_id !== null) {
                    return $locked;
                }

                $entry = $this->ledger->appendEntry(
                    centralWalletId: (string) $locked->cwid,
                    entryType: LedgerEntryType::Credit,
                    amount: (string) $locked->amount,
                    sourceSystem: 'radium-desk',
                    correlationId: $correlationId,
                    sourceReference: $locked->source_reference,
                    businessReference: $locked->refund_reference,
                    metadata: [
                        'migration_type' => E2HistoricalSettlementClassification::MIGRATION_TYPE,
                        'settlement_classification' => E2HistoricalSettlementClassification::OWNER_APPROVED_HISTORICAL_MANUAL_REFUND_SETTLEMENT,
                        'settlement_audit_disclaimer' => E2HistoricalSettlementClassification::AUDIT_DISCLAIMER,
                        'refund_id' => $locked->refund_id,
                        'refund_reference' => $locked->refund_reference,
                        'desk_customer_id' => $locked->desk_customer_id,
                        'batch_id' => $locked->batch_id,
                        'idempotency_key' => $locked->idempotency_key,
                        'owner_approval_ref' => $ownerApprovalRef,
                        'forensic_report_ref' => $locked->metadata['forensic_report_ref'] ?? E2HistoricalSettlementClassification::FORENSIC_REPORT_REF,
                        'forensic_classification' => $locked->metadata['forensic_classification'] ?? 'CORROBORATING_ONLY',
                        'source_wallet_provenance' => 'unavailable_not_reconstructed',
                    ],
                );

                $this->stateMachine->assertCanTransition($locked->status, RefundMigrationStatus::CwCredited);
                $locked->fill([
                    'status' => RefundMigrationStatus::CwCredited,
                    'destination_ledger_entry_id' => $entry->id,
                    'owner_approval_ref' => $ownerApprovalRef,
                    'executed_at' => now(),
                ]);
                $locked->save();

                $this->auditEvents->record(
                    eventType: 'refund_migration.historical_settlement_credited',
                    centralWalletId: (string) $locked->cwid,
                    actorType: AuditActorType::Service,
                    actorId: $actorId,
                    correlationId: $correlationId,
                    payload: [
                        'refund_id' => $locked->refund_id,
                        'ledger_entry_id' => $entry->id,
                        'amount' => (string) $locked->amount,
                        'settlement_classification' => E2HistoricalSettlementClassification::OWNER_APPROVED_HISTORICAL_MANUAL_REFUND_SETTLEMENT,
                    ],
                );

                return $locked->fresh();
            });
        } catch (\Throwable $exception) {
            $this->transition($migration, RefundMigrationStatus::Failed, [
                'error_code' => 'historical_settlement_credit_failed',
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return $this->finalizeReconciled($migration);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(
        CentralWalletRefundMigration $migration,
        RefundMigrationStatus $status,
        array $attributes = [],
    ): CentralWalletRefundMigration {
        $this->stateMachine->assertCanTransition($migration->status, $status);
        $migration->fill(array_merge(['status' => $status], $attributes));
        $migration->save();

        return $migration->fresh();
    }

    /**
     * @return array{status: string, ledger_entry_id?: int, idempotent_replay?: bool}
     */
    private function finalizeReconciled(CentralWalletRefundMigration $migration): array
    {
        if ($migration->status !== RefundMigrationStatus::Reconciled) {
            $migration = $this->transition($migration, RefundMigrationStatus::Reconciled);
        }

        return [
            'status' => $migration->status->value,
            'ledger_entry_id' => $migration->destination_ledger_entry_id,
        ];
    }
}
