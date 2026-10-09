<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Domain\Enums\BalanceMigrationStatus;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Domain\RefundMigrationIdempotencyKey;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletBalanceMigration;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use App\Enums\ApprovedRefundMethod;
use App\Models\RefundRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PilotRefundMigrationReprepareService
{
    private const REASON = 'pilot_refund_migration_recovery_reprepare';

    public function __construct(
        private readonly RefundMigrationStateMachine $stateMachine,
        private readonly AuditEventRecorder $auditEvents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function reprepare(
        int $refundId,
        string $ownerRecoveryRef,
        string $correlationId,
        string $actorId,
    ): array {
        $this->assertAllowlistedRefundId($refundId);
        $ownerRecoveryRef = trim($ownerRecoveryRef);
        if ($ownerRecoveryRef === '') {
            throw new InvalidArgumentException('owner_recovery_ref_required');
        }

        $expectedOwner = trim((string) config('central_wallet.pilot_refund_migration.required_owner_approval_ref', ''));
        if ($expectedOwner !== '' && ! hash_equals($expectedOwner, $ownerRecoveryRef)) {
            throw new InvalidArgumentException('owner_recovery_ref_mismatch');
        }

        return DB::transaction(function () use ($refundId, $ownerRecoveryRef, $correlationId, $actorId): array {
            $migration = CentralWalletRefundMigration::query()
                ->where('refund_id', $refundId)
                ->lockForUpdate()
                ->first();

            if ($migration === null) {
                throw new InvalidArgumentException('refund_migration_not_found');
            }

            $existingAttempt = RefundMigrationBalanceAttempt::fromMigration($migration);
            if ($migration->status === RefundMigrationStatus::Prepared && $existingAttempt !== null) {
                return $this->idempotentResponse($migration, $existingAttempt, true);
            }

            if ($migration->status !== RefundMigrationStatus::ReconciliationRequired) {
                throw new InvalidArgumentException('refund_migration_not_reprepare_candidate');
            }

            if (RefundMigrationFinancialEvidence::isReconciledWithEvidence($migration)) {
                throw new InvalidArgumentException('refund_migration_has_financial_evidence');
            }

            if ($migration->destination_ledger_entry_id !== null) {
                throw new InvalidArgumentException('destination_ledger_already_present');
            }

            if ($migration->source_debit_reference !== null) {
                throw new InvalidArgumentException('source_retirement_already_present');
            }

            $this->assertRefundLiveDataMatchesPilot($refundId, $migration);
            $this->assertNoDestinationLedgerCredit($migration);

            $parentOperationId = trim((string) ($migration->balance_migration_operation_id ?? ''));
            if ($parentOperationId === '') {
                throw new InvalidArgumentException('parent_balance_operation_missing');
            }

            $parentBalance = CentralWalletBalanceMigration::query()
                ->where('migration_operation_id', $parentOperationId)
                ->first();

            if ($parentBalance === null) {
                throw new InvalidArgumentException('parent_balance_operation_not_found');
            }

            if ($parentBalance->status !== BalanceMigrationStatus::Aborted) {
                throw new InvalidArgumentException('parent_balance_operation_not_aborted');
            }

            $nextAttempt = $this->nextSourceWalletAttempt($parentBalance);
            $attemptOperationId = (string) Str::uuid();
            $cutoverIdempotencyKey = RefundMigrationIdempotencyKey::forRefundBalanceAttempt($refundId, $attemptOperationId);

            $fromStatus = $migration->status;
            $this->stateMachine->assertCanTransition($fromStatus, RefundMigrationStatus::Prepared);

            $metadata = $migration->metadata ?? [];
            $metadata[RefundMigrationBalanceAttempt::METADATA_KEY] = [
                'parent_operation_id' => $parentOperationId,
                'attempt_operation_id' => $attemptOperationId,
                'cutover_idempotency_key' => $cutoverIdempotencyKey,
                'source_wallet_attempt' => $nextAttempt,
                'owner_recovery_ref' => $ownerRecoveryRef,
                'reprepared_at' => now()->toIso8601String(),
                'reason' => self::REASON,
            ];

            $migration->fill([
                'status' => RefundMigrationStatus::Prepared,
                'balance_migration_operation_id' => null,
                'error_code' => null,
                'error_message' => null,
                'executed_at' => null,
                'metadata' => $metadata,
                'prepared_at' => now(),
            ]);
            $migration->save();

            $this->auditEvents->record(
                eventType: 'refund_migration.reprepared',
                centralWalletId: $migration->cwid,
                actorType: AuditActorType::Service,
                actorId: $actorId,
                correlationId: $correlationId,
                payload: [
                    'refund_id' => $migration->refund_id,
                    'refund_reference' => $migration->refund_reference,
                    'previous_status' => $fromStatus->value,
                    'new_status' => RefundMigrationStatus::Prepared->value,
                    'previous_balance_operation_id' => $parentOperationId,
                    'attempt_operation_id' => $attemptOperationId,
                    'cutover_idempotency_key' => $cutoverIdempotencyKey,
                    'source_wallet_attempt' => $nextAttempt,
                    'owner_recovery_ref' => $ownerRecoveryRef,
                    'reason' => self::REASON,
                ],
            );

            $attempt = RefundMigrationBalanceAttempt::fromMigration($migration->refresh());

            return $this->idempotentResponse($migration, $attempt ?? [], false);
        });
    }

    private function assertAllowlistedRefundId(int $refundId): void
    {
        $allowed = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) config('central_wallet.pilot_refund_migration.allowed_refund_ids', '387')),
        )));

        if ($allowed === [] || ! in_array($refundId, $allowed, true)) {
            throw new InvalidArgumentException('pilot_refund_id_not_allowlisted');
        }
    }

    private function assertRefundLiveDataMatchesPilot(int $refundId, CentralWalletRefundMigration $migration): void
    {
        $refund = RefundRequest::query()->find($refundId);
        if ($refund === null) {
            throw new InvalidArgumentException('refund_not_found');
        }

        if ($refund->approved_refund_method !== ApprovedRefundMethod::Wallet) {
            throw new InvalidArgumentException('refund_not_wallet_method');
        }

        if (! in_array($refund->status?->value, ['closed', 'completed'], true)) {
            throw new InvalidArgumentException('refund_not_terminal');
        }

        if (bccomp((string) $refund->refund_amount, '499.00', 2) !== 0) {
            throw new InvalidArgumentException('refund_amount_mismatch');
        }

        if ((string) $refund->execution_transaction_id !== '2663') {
            throw new InvalidArgumentException('execution_transaction_mismatch');
        }

        if ((int) $migration->source_wallet_id !== 2663) {
            throw new InvalidArgumentException('source_wallet_mismatch');
        }
    }

    private function assertNoDestinationLedgerCredit(CentralWalletRefundMigration $migration): void
    {
        $exists = CentralWalletLedgerEntry::query()
            ->where('central_wallet_id', $migration->cwid)
            ->where('business_reference', $migration->refund_reference)
            ->where('entry_type', 'credit')
            ->exists();

        if ($exists) {
            throw new InvalidArgumentException('destination_ledger_credit_exists');
        }
    }

    private function nextSourceWalletAttempt(CentralWalletBalanceMigration $parentBalance): int
    {
        $maxAttempt = (int) CentralWalletBalanceMigration::query()
            ->where('source_site_code', $parentBalance->source_site_code)
            ->where('source_users_wallet_id', $parentBalance->source_users_wallet_id)
            ->max('source_wallet_attempt');

        return $maxAttempt + 1;
    }

    /**
     * @param  array<string, mixed>  $attempt
     * @return array<string, mixed>
     */
    private function idempotentResponse(
        CentralWalletRefundMigration $migration,
        array $attempt,
        bool $idempotentReplay,
    ): array {
        return [
            'refund_id' => $migration->refund_id,
            'refund_reference' => $migration->refund_reference,
            'status' => $migration->status->value,
            'parent_balance_operation_id' => $attempt['parent_operation_id'] ?? null,
            'attempt_operation_id' => $attempt['attempt_operation_id'] ?? null,
            'cutover_idempotency_key' => $attempt['cutover_idempotency_key'] ?? null,
            'source_wallet_attempt' => $attempt['source_wallet_attempt'] ?? null,
            'idempotent_replay' => $idempotentReplay,
        ];
    }
}
