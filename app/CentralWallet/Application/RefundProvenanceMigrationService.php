<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RefundProvenanceMigrationService
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
                        'migration_type' => 'refund_provenance_historical_migration',
                        'refund_id' => $locked->refund_id,
                        'refund_reference' => $locked->refund_reference,
                        'desk_customer_id' => $locked->desk_customer_id,
                        'batch_id' => $locked->batch_id,
                        'idempotency_key' => $locked->idempotency_key,
                        'owner_approval_ref' => $ownerApprovalRef,
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
                    eventType: 'refund_migration.provenance_credited',
                    centralWalletId: (string) $locked->cwid,
                    actorType: AuditActorType::Service,
                    actorId: $actorId,
                    correlationId: $correlationId,
                    payload: [
                        'refund_id' => $locked->refund_id,
                        'ledger_entry_id' => $entry->id,
                        'amount' => (string) $locked->amount,
                    ],
                );

                return $locked->refresh();
            });
        } catch (InvalidArgumentException $exception) {
            $this->transition($migration, RefundMigrationStatus::Failed, [
                'error_code' => 'provenance_credit_failed',
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return $this->finalizeReconciled($migration);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function transition(
        CentralWalletRefundMigration $migration,
        RefundMigrationStatus $to,
        array $extra = [],
    ): CentralWalletRefundMigration {
        return DB::transaction(function () use ($migration, $to, $extra): CentralWalletRefundMigration {
            $locked = CentralWalletRefundMigration::query()
                ->where('id', $migration->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->stateMachine->assertCanTransition($locked->status, $to);
            $locked->fill(array_merge(['status' => $to], $extra));
            $locked->save();

            return $locked->refresh();
        });
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
            'idempotent_replay' => false,
        ];
    }
}
