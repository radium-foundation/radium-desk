<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Application\Contracts\WalletMigrationSpokeClient;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Domain\E2HistoricalSettlementIdempotencyKey;
use App\CentralWallet\Domain\RefundMigrationIdempotencyKey;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RefundMigrationRollbackService
{
    public function __construct(
        private readonly RefundMigrationStateMachine $stateMachine,
        private readonly LedgerService $ledger,
        private readonly WalletMigrationSpokeClient $spokeClient,
        private readonly AuditEventRecorder $auditEvents,
    ) {}

    /**
     * @return array{status: string, reversal_ledger_entry_id?: int, idempotent_replay?: bool}
     */
    public function rollback(
        CentralWalletRefundMigration $migration,
        string $ownerApprovalRef,
        string $correlationId,
        string $actorId,
    ): array {
        if ($migration->status === RefundMigrationStatus::Reversed) {
            return [
                'status' => $migration->status->value,
                'reversal_ledger_entry_id' => $migration->reversal_ledger_entry_id,
                'idempotent_replay' => true,
            ];
        }

        if ($migration->destination_ledger_entry_id === null) {
            throw new InvalidArgumentException('nothing_to_rollback');
        }

        if ($migration->reversal_ledger_entry_id !== null) {
            return $this->markReversed($migration);
        }

        $original = CentralWalletLedgerEntry::query()->find($migration->destination_ledger_entry_id);
        if ($original === null) {
            throw new InvalidArgumentException('original_ledger_entry_not_found');
        }

        $migration = $this->transition($migration, RefundMigrationStatus::Compensating);

        $reversal = DB::transaction(function () use ($migration, $original, $correlationId, $ownerApprovalRef): CentralWalletLedgerEntry {
            $existing = CentralWalletLedgerEntry::query()
                ->where('entry_type', LedgerEntryType::Reversal->value)
                ->where('original_ledger_entry_id', $original->id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $rollbackSourceReference = $migration->lane->isHistoricalSettlement()
                ? E2HistoricalSettlementIdempotencyKey::rollback((int) $migration->refund_id)
                : RefundMigrationIdempotencyKey::rollback((int) $migration->refund_id);

            return $this->ledger->appendEntry(
                centralWalletId: (string) $migration->cwid,
                entryType: LedgerEntryType::Reversal,
                amount: (string) $migration->amount,
                sourceSystem: 'radium-desk',
                correlationId: $correlationId,
                sourceReference: $rollbackSourceReference,
                businessReference: $migration->refund_reference,
                originalLedgerEntryId: $original->id,
                metadata: [
                    'rollback_type' => $migration->lane->isHistoricalSettlement()
                        ? 'e2_historical_settlement_reversal'
                        : 'refund_migration_reversal',
                    'refund_id' => $migration->refund_id,
                    'owner_approval_ref' => $ownerApprovalRef,
                ],
            );
        });

        $migration = $this->transition($migration, RefundMigrationStatus::Compensated, [
            'reversal_ledger_entry_id' => $reversal->id,
        ]);

        if ($migration->lane === RefundMigrationLane::Lane1SpokeCutover) {
            $this->restoreSpokeSource($migration, $correlationId);
        }

        $this->auditEvents->record(
            eventType: 'refund_migration.rolled_back',
            centralWalletId: (string) $migration->cwid,
            actorType: AuditActorType::Service,
            actorId: $actorId,
            correlationId: $correlationId,
            payload: [
                'refund_id' => $migration->refund_id,
                'reversal_ledger_entry_id' => $reversal->id,
            ],
        );

        return $this->markReversed($migration);
    }

    private function restoreSpokeSource(CentralWalletRefundMigration $migration, string $correlationId): void
    {
        if ($migration->source_application === null || $migration->source_wallet_id === null) {
            return;
        }

        $localUserId = (string) ($migration->metadata['order_resolved_user_id'] ?? '');
        if ($localUserId === '') {
            return;
        }

        $result = $this->spokeClient->restoreSourceCredit(
            migrationOperationId: (string) ($migration->balance_migration_operation_id ?? $migration->id),
            sourceSiteCode: $migration->source_application,
            sourceLocalUserId: $localUserId,
            sourceUsersWalletId: (int) $migration->source_wallet_id,
            amount: (string) $migration->amount,
            sourceBusinessReference: $migration->refund_reference,
            rollbackIdempotencyKey: RefundMigrationIdempotencyKey::rollback((int) $migration->refund_id),
        );

        if ($result['status'] >= 400) {
            $this->transition($migration, RefundMigrationStatus::ReconciliationRequired, [
                'error_code' => 'spoke_restore_failed',
                'error_message' => json_encode($result['body'], JSON_THROW_ON_ERROR),
            ]);

            throw new InvalidArgumentException('spoke_restore_failed');
        }
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
     * @return array{status: string, reversal_ledger_entry_id?: int, idempotent_replay?: bool}
     */
    private function markReversed(CentralWalletRefundMigration $migration): array
    {
        $migration = $this->transition($migration, RefundMigrationStatus::Reversed, [
            'reversed_at' => now(),
        ]);

        return [
            'status' => $migration->status->value,
            'reversal_ledger_entry_id' => $migration->reversal_ledger_entry_id,
            'idempotent_replay' => false,
        ];
    }
}
