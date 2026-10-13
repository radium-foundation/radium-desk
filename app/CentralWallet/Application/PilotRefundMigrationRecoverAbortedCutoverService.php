<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Application\Contracts\WalletMigrationSpokeClient;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Domain\Enums\BalanceMigrationStatus;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Domain\RefundMigrationIdempotencyKey;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletBalanceMigration;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PilotRefundMigrationRecoverAbortedCutoverService
{
    public const RECOVERY_REASON = 'pilot_refund_migration_recovery_aborted_cutover';

    /**
     * @var list<string>
     */
    private const ALLOWED_JOURNAL_ERROR_CODES = [
        'spoke_lock_failed',
        'migration_aborted',
    ];

    /**
     * @var list<string>
     */
    private const ACTIVE_SPOKE_LOCK_STATUSES = [
        'active',
        'acquired',
        'held',
    ];

    public function __construct(
        private readonly RefundMigrationStateMachine $stateMachine,
        private readonly AuditEventRecorder $auditEvents,
        private readonly PilotRefundMigrationManifestLoader $manifestLoader,
        private readonly PilotRefundMigrationPreflightGate $preflightGate,
        private readonly WalletMigrationSpokeClient $spokeClient,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function recover(
        int $refundId,
        string $ownerRecoveryRef,
        string $correlationId,
        string $actorId,
        ?string $manifestPath = null,
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

        $manifest = $this->manifestLoader->load($manifestPath);
        $manifestRow = $this->manifestRowForRefund($manifest, $refundId);

        return DB::transaction(function () use (
            $refundId,
            $ownerRecoveryRef,
            $correlationId,
            $actorId,
            $manifestRow,
        ): array {
            $migration = CentralWalletRefundMigration::query()
                ->where('refund_id', $refundId)
                ->lockForUpdate()
                ->first();

            if ($migration === null) {
                throw new InvalidArgumentException('refund_migration_not_found');
            }

            $existingAttempt = RefundMigrationBalanceAttempt::fromMigration($migration);
            if ($migration->status === RefundMigrationStatus::Prepared
                && $existingAttempt !== null
                && ($existingAttempt['reason'] ?? '') === self::RECOVERY_REASON) {
                return $this->idempotentResponse($migration, $existingAttempt, true);
            }

            $this->assertFailedAbortedCutoverCandidate($migration);
            $this->assertJournalMatchesManifest($migration, $manifestRow);
            $this->assertNoLiveBlockers($manifestRow);

            if ($migration->destination_ledger_entry_id !== null) {
                throw new InvalidArgumentException('destination_ledger_already_present');
            }

            if ($migration->source_debit_reference !== null) {
                throw new InvalidArgumentException('source_retirement_already_present');
            }

            if (RefundMigrationFinancialEvidence::isReconciledWithEvidence($migration)) {
                throw new InvalidArgumentException('refund_migration_has_financial_evidence');
            }

            $this->assertNoDestinationLedgerCredit($migration);

            $parentBalance = $this->resolveUniqueAbortedParent($refundId);
            $this->assertParentMatchesJournalAndManifest($parentBalance, $migration, $manifestRow);
            $this->assertNoActiveSpokeLock($parentBalance, $manifestRow);

            $nextAttempt = $this->nextSourceWalletAttempt($parentBalance);
            $parentOperationId = (string) $parentBalance->migration_operation_id;
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
                'reason' => self::RECOVERY_REASON,
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
                eventType: 'refund_migration.recovered_aborted_cutover',
                centralWalletId: $migration->cwid,
                actorType: AuditActorType::Service,
                actorId: $actorId,
                correlationId: $correlationId,
                payload: [
                    'refund_id' => $migration->refund_id,
                    'refund_reference' => $migration->refund_reference,
                    'previous_status' => $fromStatus->value,
                    'new_status' => RefundMigrationStatus::Prepared->value,
                    'parent_balance_operation_id' => $parentOperationId,
                    'attempt_operation_id' => $attemptOperationId,
                    'cutover_idempotency_key' => $cutoverIdempotencyKey,
                    'source_wallet_attempt' => $nextAttempt,
                    'owner_recovery_ref' => $ownerRecoveryRef,
                    'reason' => self::RECOVERY_REASON,
                    'aborted_parent_idempotency_key' => $parentBalance->idempotency_key,
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

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function manifestRowForRefund(array $manifest, int $refundId): array
    {
        foreach ($manifest['rows'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            if ((int) ($row['refund_id'] ?? 0) === $refundId) {
                return $row;
            }
        }

        throw new InvalidArgumentException('refund_not_in_manifest');
    }

    private function assertFailedAbortedCutoverCandidate(CentralWalletRefundMigration $migration): void
    {
        if ($migration->status !== RefundMigrationStatus::Failed) {
            throw new InvalidArgumentException('refund_migration_not_aborted_cutover_candidate');
        }

        $errorCode = trim((string) ($migration->error_code ?? ''));
        if ($errorCode === '' || ! in_array($errorCode, self::ALLOWED_JOURNAL_ERROR_CODES, true)) {
            throw new InvalidArgumentException('refund_migration_error_not_recoverable');
        }

        $linkedOperation = trim((string) ($migration->balance_migration_operation_id ?? ''));
        if ($linkedOperation !== '') {
            throw new InvalidArgumentException('journal_balance_operation_already_set');
        }
    }

    /**
     * @param  array<string, mixed>  $manifestRow
     */
    private function assertJournalMatchesManifest(CentralWalletRefundMigration $migration, array $manifestRow): void
    {
        if ((string) $migration->refund_reference !== (string) ($manifestRow['desk_refund_reference'] ?? '')) {
            throw new InvalidArgumentException('refund_reference_mismatch');
        }

        if (bccomp((string) $migration->amount, (string) ($manifestRow['refund_amount'] ?? '0'), 2) !== 0) {
            throw new InvalidArgumentException('amount_mismatch');
        }

        if ((string) $migration->cwid !== (string) ($manifestRow['cwid'] ?? '')) {
            throw new InvalidArgumentException('cwid_manifest_mismatch');
        }

        if ((string) $migration->desk_customer_id !== (string) ($manifestRow['desk_customer_id'] ?? '')) {
            throw new InvalidArgumentException('desk_customer_manifest_mismatch');
        }

        if ((int) $migration->source_wallet_id !== (int) ($manifestRow['source_wallet_id'] ?? 0)) {
            throw new InvalidArgumentException('source_wallet_mismatch');
        }
    }

    /**
     * @param  array<string, mixed>  $manifestRow
     */
    private function assertNoLiveBlockers(array $manifestRow): void
    {
        $blockers = $this->preflightGate->liveRefundBlockersForManifestRow($manifestRow);
        if ($blockers !== []) {
            $first = $blockers[0]['code'] ?? 'live_preflight_failed';

            throw new InvalidArgumentException((string) $first);
        }
    }

    private function resolveUniqueAbortedParent(int $refundId): CentralWalletBalanceMigration
    {
        $idempotencyKey = RefundMigrationIdempotencyKey::forRefund($refundId);
        $parents = CentralWalletBalanceMigration::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', BalanceMigrationStatus::Aborted)
            ->orderBy('created_at')
            ->get();

        if ($parents->count() === 0) {
            throw new InvalidArgumentException('aborted_parent_balance_operation_not_found');
        }

        if ($parents->count() > 1) {
            throw new InvalidArgumentException('aborted_parent_balance_operation_ambiguous');
        }

        return $parents->first();
    }

    /**
     * @param  array<string, mixed>  $manifestRow
     */
    private function assertParentMatchesJournalAndManifest(
        CentralWalletBalanceMigration $parent,
        CentralWalletRefundMigration $migration,
        array $manifestRow,
    ): void {
        if ($parent->status !== BalanceMigrationStatus::Aborted) {
            throw new InvalidArgumentException('parent_balance_operation_not_aborted');
        }

        $parentFailure = trim((string) ($parent->failure_code ?? ''));
        if ($parentFailure === '' || ! in_array($parentFailure, self::ALLOWED_JOURNAL_ERROR_CODES, true)) {
            throw new InvalidArgumentException('parent_failure_not_recoverable');
        }

        if ((string) $parent->source_business_reference !== (string) $migration->refund_reference) {
            throw new InvalidArgumentException('parent_business_reference_mismatch');
        }

        if (bccomp((string) $parent->source_amount, (string) $migration->amount, 2) !== 0) {
            throw new InvalidArgumentException('parent_amount_mismatch');
        }

        if ((string) $parent->destination_central_wallet_id !== (string) $migration->cwid) {
            throw new InvalidArgumentException('parent_destination_cwid_mismatch');
        }

        if ((int) $parent->source_users_wallet_id !== (int) $migration->source_wallet_id) {
            throw new InvalidArgumentException('parent_source_wallet_mismatch');
        }

        $expectedSite = strtolower(trim((string) ($manifestRow['site'] ?? '')));
        $siteMap = [
            'rdservice.in' => 'rdservice.in',
            'radiumbox.com' => 'radiumbox.com',
        ];
        $normalizedSite = $siteMap[$expectedSite] ?? $expectedSite;
        if (strtolower((string) $parent->source_site_code) !== $normalizedSite) {
            throw new InvalidArgumentException('parent_source_site_mismatch');
        }

        if ((string) $parent->source_currency !== 'INR') {
            throw new InvalidArgumentException('parent_source_currency_mismatch');
        }
    }

    /**
     * @param  array<string, mixed>  $manifestRow
     */
    private function assertNoActiveSpokeLock(
        CentralWalletBalanceMigration $parent,
        array $manifestRow,
    ): void {
        $localUserId = trim((string) ($manifestRow['order_resolved_user_id'] ?? ''));
        if ($localUserId === '') {
            throw new InvalidArgumentException('missing_source_local_user_id');
        }

        $response = $this->spokeClient->getMigrationStatus(
            migrationOperationId: (string) $parent->migration_operation_id,
            sourceSiteCode: (string) $parent->source_site_code,
            sourceLocalUserId: $localUserId,
            sourceUsersWalletId: (int) $parent->source_users_wallet_id,
            amount: (string) $parent->source_amount,
            sourceBusinessReference: (string) $parent->source_business_reference,
        );

        if ($response['status'] >= 500) {
            throw new InvalidArgumentException('spoke_status_unavailable');
        }

        if ($response['status'] >= 400) {
            return;
        }

        $lockStatus = strtolower(trim((string) ($response['body']['lock_status'] ?? '')));
        if ($lockStatus !== '' && in_array($lockStatus, self::ACTIVE_SPOKE_LOCK_STATUSES, true)) {
            throw new InvalidArgumentException('spoke_migration_lock_active');
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
