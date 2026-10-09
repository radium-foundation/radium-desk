<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\BalanceMigrationStatus;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletBalanceMigration;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RefundMigrationLane1Executor
{
    public function __construct(
        private readonly RefundMigrationStateMachine $stateMachine,
        private readonly BalanceMigrationCutoverService $cutoverService,
    ) {}

    /**
     * @return array{status: string, migration_operation_id?: string, ledger_entry_id?: int}
     */
    public function execute(
        CentralWalletRefundMigration $migration,
        string $ownerApprovalRef,
        string $correlationId,
        string $actorId,
    ): array {
        if ($migration->source_application === null || $migration->source_wallet_id === null) {
            throw new InvalidArgumentException('missing_spoke_source');
        }

        if ($migration->cwid === null || $migration->desk_customer_id === null) {
            throw new InvalidArgumentException('missing_target_identity');
        }

        if ($migration->status === RefundMigrationStatus::Reconciled) {
            if (! RefundMigrationFinancialEvidence::isReconciledWithEvidence($migration)) {
                throw new InvalidArgumentException('refund_migration_reconciled_without_evidence');
            }

            return [
                'status' => $migration->status->value,
                'migration_operation_id' => $migration->balance_migration_operation_id,
                'ledger_entry_id' => $migration->destination_ledger_entry_id,
            ];
        }

        $localUserId = (string) ($migration->metadata['order_resolved_user_id'] ?? '');
        if ($localUserId === '') {
            $resolution = $migration->metadata['source_local_user_id'] ?? null;
            $localUserId = is_string($resolution) ? $resolution : '';
        }

        if ($localUserId === '') {
            throw new InvalidArgumentException('missing_source_local_user_id');
        }

        $migration = $this->transition($migration, RefundMigrationStatus::SourceDebitPending);

        $cutoverPayload = array_merge([
            'source_site_code' => $migration->source_application,
            'source_local_user_id' => $localUserId,
            'source_users_wallet_id' => (int) $migration->source_wallet_id,
            'source_order_reference' => (string) ($migration->order_number ?? ''),
            'source_business_reference' => $migration->refund_reference,
            'source_amount' => (string) $migration->amount,
            'source_currency' => 'INR',
            'destination_central_wallet_id' => $migration->cwid,
            'migration_batch_id' => $migration->batch_id,
            'owner_approval_ref' => $ownerApprovalRef,
        ], RefundMigrationBalanceAttempt::cutoverOverrides($migration));

        $result = $this->cutoverService->execute($cutoverPayload, 'refund_migration', $correlationId, $actorId);

        if ($result['status'] >= 400) {
            $errorCode = (string) ($result['body']['error'] ?? 'lane1_cutover_failed');
            $status = $errorCode === 'source_retirement_failed'
                ? RefundMigrationStatus::Compensating
                : RefundMigrationStatus::Failed;

            $this->transition($migration, $status, [
                'error_code' => $errorCode,
                'error_message' => json_encode($result['body'], JSON_THROW_ON_ERROR),
            ]);

            throw new InvalidArgumentException($errorCode);
        }

        $operationId = (string) ($result['body']['migration_operation_id'] ?? '');
        $balanceMigration = $operationId !== ''
            ? CentralWalletBalanceMigration::query()->where('migration_operation_id', $operationId)->first()
            : null;

        if (! $this->cutoverFinanciallyComplete($balanceMigration, $result['body'])) {
            $errorCode = 'balance_migration_financial_evidence_missing';
            $this->transition($migration, RefundMigrationStatus::Failed, [
                'error_code' => $errorCode,
                'error_message' => json_encode($result['body'], JSON_THROW_ON_ERROR),
            ]);

            throw new InvalidArgumentException($errorCode);
        }

        $migration = $this->transition($migration, RefundMigrationStatus::SourceDebited, [
            'balance_migration_operation_id' => $operationId !== '' ? $operationId : null,
            'source_debit_reference' => $balanceMigration?->source_retirement_reference,
        ]);

        $migration = $this->transition($migration, RefundMigrationStatus::CwCreditPending);

        $migration = $this->transition($migration, RefundMigrationStatus::CwCredited, [
            'destination_ledger_entry_id' => $balanceMigration?->destination_ledger_entry_id
                ?? $result['body']['destination_ledger_entry_id'] ?? null,
            'executed_at' => now(),
            'owner_approval_ref' => $ownerApprovalRef,
        ]);

        $migration = $this->transition($migration, RefundMigrationStatus::Reconciled);

        return [
            'status' => $migration->status->value,
            'migration_operation_id' => $migration->balance_migration_operation_id,
            'ledger_entry_id' => $migration->destination_ledger_entry_id,
        ];
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
     * @param  array<string, mixed>  $cutoverBody
     */
    private function cutoverFinanciallyComplete(
        ?CentralWalletBalanceMigration $balanceMigration,
        array $cutoverBody,
    ): bool {
        if ($balanceMigration === null) {
            return false;
        }

        if ($balanceMigration->status !== BalanceMigrationStatus::Reconciled) {
            return false;
        }

        $ledgerId = $balanceMigration->destination_ledger_entry_id
            ?? $cutoverBody['destination_ledger_entry_id']
            ?? null;
        $retirementRef = $balanceMigration->source_retirement_reference
            ?? $cutoverBody['source_retirement_reference']
            ?? null;

        return filled($ledgerId) && filled($retirementRef);
    }
}
