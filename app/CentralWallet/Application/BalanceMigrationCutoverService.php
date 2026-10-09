<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Application\Contracts\WalletMigrationSpokeClient;
use App\CentralWallet\Domain\BalanceMigrationIdempotencyKey;
use App\CentralWallet\Domain\Cwid;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Domain\Enums\BalanceMigrationStatus;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletBalanceMigration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class BalanceMigrationCutoverService
{
    public function __construct(
        private readonly BalanceMigrationStateMachine $stateMachine,
        private readonly LedgerService $ledger,
        private readonly AuditEventRecorder $auditEvents,
        private readonly WalletMigrationSpokeClient $spokeClient,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{status: int, body: array<string, mixed>}
     */
    public function execute(array $input, string $callerId, string $correlationId, string $actorId): array
    {
        if (! $this->executionEnabled()) {
            return $this->error('migration_execution_disabled', 403);
        }

        $ownerApprovalRef = trim((string) ($input['owner_approval_ref'] ?? ''));
        if ($ownerApprovalRef === '') {
            return $this->error('owner_approval_required', 403);
        }

        try {
            $normalized = $this->normalizeInput($input);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        $existing = CentralWalletBalanceMigration::query()
            ->where('idempotency_key', $normalized['idempotency_key'])
            ->first();

        if ($existing !== null) {
            return $this->resumeOrReplay($existing, $normalized, $correlationId, $actorId);
        }

        try {
            $migration = $this->createMigration($normalized, $correlationId, $actorId, $ownerApprovalRef);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                $existing = CentralWalletBalanceMigration::query()
                    ->where('idempotency_key', $normalized['idempotency_key'])
                    ->firstOrFail();

                return $this->resumeOrReplay($existing, $normalized, $correlationId, $actorId);
            }

            throw $exception;
        }

        return $this->advance($migration, $normalized, $correlationId, $actorId);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalizeInput(array $input): array
    {
        $sourceSiteCode = strtolower(trim((string) ($input['source_site_code'] ?? '')));
        $sourceLocalUserId = trim((string) ($input['source_local_user_id'] ?? ''));
        $sourceUsersWalletId = (int) ($input['source_users_wallet_id'] ?? 0);
        $sourceOrderReference = trim((string) ($input['source_order_reference'] ?? ''));
        $sourceBusinessReference = trim((string) ($input['source_business_reference'] ?? ''));
        $sourceAmount = trim((string) ($input['source_amount'] ?? ''));
        $sourceCurrency = strtoupper(trim((string) ($input['source_currency'] ?? 'INR')));
        $destinationCwid = trim((string) ($input['destination_central_wallet_id'] ?? ''));
        $migrationBatchId = trim((string) ($input['migration_batch_id'] ?? ''));
        $sourceCreatedAt = $input['source_created_at'] ?? null;

        if ($sourceSiteCode === '' || $sourceLocalUserId === '' || $sourceUsersWalletId <= 0) {
            throw new InvalidArgumentException('invalid_source_identity');
        }

        if ($sourceOrderReference === '' || $sourceBusinessReference === '') {
            throw new InvalidArgumentException('invalid_source_provenance');
        }

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $sourceAmount) || bccomp($sourceAmount, '0', 2) <= 0) {
            throw new InvalidArgumentException('invalid_source_amount');
        }

        if ($sourceCurrency !== 'INR') {
            throw new InvalidArgumentException('invalid_source_currency');
        }

        Cwid::fromString($destinationCwid);

        if (CentralWallet::query()->find($destinationCwid) === null) {
            throw new InvalidArgumentException('destination_wallet_not_found');
        }

        $idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            $idempotencyKey = BalanceMigrationIdempotencyKey::forSourceLedgerRow(
                $sourceSiteCode,
                $sourceUsersWalletId,
            );
        }

        return [
            'source_site_code' => $sourceSiteCode,
            'source_local_user_id' => $sourceLocalUserId,
            'source_users_wallet_id' => $sourceUsersWalletId,
            'source_order_reference' => $sourceOrderReference,
            'source_business_reference' => $sourceBusinessReference,
            'source_amount' => $sourceAmount,
            'source_currency' => $sourceCurrency,
            'source_created_at' => $sourceCreatedAt,
            'destination_central_wallet_id' => $destinationCwid,
            'migration_batch_id' => $migrationBatchId !== '' ? $migrationBatchId : null,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function createMigration(
        array $normalized,
        string $correlationId,
        string $actorId,
        string $ownerApprovalRef,
    ): CentralWalletBalanceMigration {
        $migrationOperationId = (string) Str::uuid();

        $migration = CentralWalletBalanceMigration::query()->create([
            'migration_operation_id' => $migrationOperationId,
            'migration_batch_id' => $normalized['migration_batch_id'],
            'status' => BalanceMigrationStatus::Initiated,
            'source_site_code' => $normalized['source_site_code'],
            'source_local_user_id' => $normalized['source_local_user_id'],
            'source_users_wallet_id' => $normalized['source_users_wallet_id'],
            'source_order_reference' => $normalized['source_order_reference'],
            'source_business_reference' => $normalized['source_business_reference'],
            'source_amount' => $normalized['source_amount'],
            'source_currency' => $normalized['source_currency'],
            'source_created_at' => $normalized['source_created_at'],
            'destination_central_wallet_id' => $normalized['destination_central_wallet_id'],
            'idempotency_key' => $normalized['idempotency_key'],
            'owner_approval_ref' => $ownerApprovalRef,
            'actor_id' => $actorId,
            'correlation_id' => $correlationId,
            'metadata' => [
                'provenance' => [
                    'source_site_code' => $normalized['source_site_code'],
                    'source_local_user_id' => $normalized['source_local_user_id'],
                    'source_users_wallet_id' => $normalized['source_users_wallet_id'],
                    'source_order_reference' => $normalized['source_order_reference'],
                    'source_business_reference' => $normalized['source_business_reference'],
                ],
            ],
        ]);

        $this->auditEvents->record(
            eventType: 'migration.initiated',
            centralWalletId: $migration->destination_central_wallet_id,
            actorType: AuditActorType::Service,
            actorId: $actorId,
            correlationId: $correlationId,
            payload: [
                'migration_operation_id' => $migration->migration_operation_id,
                'idempotency_key' => $migration->idempotency_key,
            ],
        );

        return $migration;
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array{status: int, body: array<string, mixed>}
     */
    private function resumeOrReplay(
        CentralWalletBalanceMigration $existing,
        array $normalized,
        string $correlationId,
        string $actorId,
    ): array {
        if ($this->payloadMismatch($existing, $normalized)) {
            return $this->error('idempotency_key_reused_with_different_request', 409);
        }

        if ($existing->status === BalanceMigrationStatus::Reconciled) {
            if (! BalanceMigrationFinancialEvidence::isReconciledWithEvidence($existing)) {
                return $this->error('migration_reconciled_without_financial_evidence', 409, $existing);
            }

            return $this->successResponse($existing, true);
        }

        if ($existing->status === BalanceMigrationStatus::Aborted) {
            $failureCode = trim((string) ($existing->failure_code ?? ''));

            return $this->error($failureCode !== '' ? $failureCode : 'migration_aborted', 422, $existing);
        }

        return $this->advance($existing, $normalized, $correlationId, $actorId);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array{status: int, body: array<string, mixed>}
     */
    private function advance(
        CentralWalletBalanceMigration $migration,
        array $normalized,
        string $correlationId,
        string $actorId,
    ): array {
        if ($migration->status === BalanceMigrationStatus::Initiated) {
            $lock = $this->spokeClient->acquireLock(
                migrationOperationId: $migration->migration_operation_id,
                sourceSiteCode: $migration->source_site_code,
                sourceLocalUserId: $migration->source_local_user_id,
                sourceUsersWalletId: $migration->source_users_wallet_id,
                amount: $migration->source_amount,
                sourceBusinessReference: $migration->source_business_reference,
            );

            if ($lock['status'] >= 400) {
                return $this->abort($migration, 'spoke_lock_failed', $lock['body'], $correlationId, $actorId);
            }

            $migration = $this->transition($migration, BalanceMigrationStatus::Prepared, [
                'prepared_at' => now(),
            ]);

            $this->auditEvents->record(
                eventType: 'migration.prepared',
                centralWalletId: $migration->destination_central_wallet_id,
                actorType: AuditActorType::Service,
                actorId: $actorId,
                correlationId: $correlationId,
                payload: ['migration_operation_id' => $migration->migration_operation_id],
            );
        }

        if ($migration->status === BalanceMigrationStatus::Prepared) {
            try {
                $migration = DB::transaction(function () use ($migration, $correlationId): CentralWalletBalanceMigration {
                    $locked = CentralWalletBalanceMigration::query()
                        ->where('migration_operation_id', $migration->migration_operation_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($locked->destination_ledger_entry_id !== null) {
                        if ($locked->status === BalanceMigrationStatus::Prepared) {
                            $this->stateMachine->assertCanTransition(
                                $locked->status,
                                BalanceMigrationStatus::CentralCredited,
                            );
                            $locked->fill([
                                'status' => BalanceMigrationStatus::CentralCredited,
                                'central_credited_at' => $locked->central_credited_at ?? now(),
                            ]);
                            $locked->save();
                        }

                        return $locked->refresh();
                    }

                    $entry = $this->ledger->appendEntry(
                        centralWalletId: $locked->destination_central_wallet_id,
                        entryType: LedgerEntryType::Credit,
                        amount: $locked->source_amount,
                        sourceSystem: $locked->source_site_code,
                        correlationId: $correlationId,
                        sourceReference: 'users_wallet:'.$locked->source_users_wallet_id,
                        businessReference: $locked->source_business_reference,
                        metadata: [
                            'migration_operation_id' => $locked->migration_operation_id,
                            'migration_type' => 'provenance_verified_balance_migration',
                            'source_local_user_id' => $locked->source_local_user_id,
                            'source_order_reference' => $locked->source_order_reference,
                            'owner_approval_ref' => $locked->owner_approval_ref,
                        ],
                    );

                    $this->stateMachine->assertCanTransition(
                        $locked->status,
                        BalanceMigrationStatus::CentralCredited,
                    );

                    $locked->fill([
                        'status' => BalanceMigrationStatus::CentralCredited,
                        'destination_ledger_entry_id' => $entry->id,
                        'central_credited_at' => now(),
                    ]);
                    $locked->save();

                    return $locked->refresh();
                });
            } catch (InvalidArgumentException $exception) {
                return $this->abort($migration, 'central_credit_failed', [
                    'error' => $exception->getMessage(),
                ], $correlationId, $actorId);
            }

            $this->auditEvents->record(
                eventType: 'migration.central_credited',
                centralWalletId: $migration->destination_central_wallet_id,
                actorType: AuditActorType::Service,
                actorId: $actorId,
                correlationId: $correlationId,
                payload: [
                    'migration_operation_id' => $migration->migration_operation_id,
                    'ledger_entry_id' => $migration->destination_ledger_entry_id,
                ],
            );
        }

        if ($migration->status === BalanceMigrationStatus::CentralCredited) {
            $retireKey = BalanceMigrationIdempotencyKey::forSourceRetirement(
                $migration->source_site_code,
                $migration->source_users_wallet_id,
            );

            $retire = $this->spokeClient->retireSource(
                migrationOperationId: $migration->migration_operation_id,
                sourceSiteCode: $migration->source_site_code,
                sourceLocalUserId: $migration->source_local_user_id,
                sourceUsersWalletId: $migration->source_users_wallet_id,
                amount: $migration->source_amount,
                sourceBusinessReference: $migration->source_business_reference,
                retirementIdempotencyKey: $retireKey,
            );

            if ($retire['status'] >= 400) {
                $migration = $this->transition($migration, BalanceMigrationStatus::Compensating, [
                    'failure_code' => 'source_retirement_failed',
                    'failure_message' => json_encode($retire['body'], JSON_THROW_ON_ERROR),
                ]);

                $this->auditEvents->record(
                    eventType: 'migration.compensating',
                    centralWalletId: $migration->destination_central_wallet_id,
                    actorType: AuditActorType::Service,
                    actorId: $actorId,
                    correlationId: $correlationId,
                    payload: ['migration_operation_id' => $migration->migration_operation_id],
                );

                return $this->error('source_retirement_failed', 502, $migration);
            }

            $retirementRef = (string) ($retire['body']['wallet_transaction_id'] ?? '');

            $migration = $this->transition($migration, BalanceMigrationStatus::SourceRetired, [
                'source_retirement_reference' => $retirementRef,
                'source_retired_at' => now(),
            ]);

            $this->auditEvents->record(
                eventType: 'migration.source_retired',
                centralWalletId: $migration->destination_central_wallet_id,
                actorType: AuditActorType::Service,
                actorId: $actorId,
                correlationId: $correlationId,
                payload: [
                    'migration_operation_id' => $migration->migration_operation_id,
                    'source_retirement_reference' => $retirementRef,
                ],
            );
        }

        if ($migration->status === BalanceMigrationStatus::SourceRetired) {
            $reconciliation = $this->spokeClient->verifyReconciliation(
                migrationOperationId: $migration->migration_operation_id,
                sourceSiteCode: $migration->source_site_code,
                sourceLocalUserId: $migration->source_local_user_id,
                sourceUsersWalletId: $migration->source_users_wallet_id,
                amount: $migration->source_amount,
                sourceBusinessReference: $migration->source_business_reference,
                retirementReference: (string) ($migration->source_retirement_reference ?? ''),
            );

            if ($reconciliation['status'] >= 400
                || ($reconciliation['body']['verified'] ?? false) !== true) {
                return $this->error('source_reconciliation_failed', 502, $migration);
            }

            $migration = $this->transition($migration, BalanceMigrationStatus::Reconciled, [
                'reconciled_at' => now(),
            ]);

            $this->auditEvents->record(
                eventType: 'migration.reconciled',
                centralWalletId: $migration->destination_central_wallet_id,
                actorType: AuditActorType::Service,
                actorId: $actorId,
                correlationId: $correlationId,
                payload: ['migration_operation_id' => $migration->migration_operation_id],
            );
        }

        return $this->successResponse($migration, false);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function transition(
        CentralWalletBalanceMigration $migration,
        BalanceMigrationStatus $to,
        array $extra = [],
    ): CentralWalletBalanceMigration {
        return DB::transaction(function () use ($migration, $to, $extra): CentralWalletBalanceMigration {
            $locked = CentralWalletBalanceMigration::query()
                ->where('migration_operation_id', $migration->migration_operation_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->stateMachine->assertCanTransition($locked->status, $to);
            $locked->fill(array_merge(['status' => $to], $extra));
            $locked->save();

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function payloadMismatch(CentralWalletBalanceMigration $existing, array $normalized): bool
    {
        return $existing->source_site_code !== $normalized['source_site_code']
            || $existing->source_local_user_id !== $normalized['source_local_user_id']
            || (int) $existing->source_users_wallet_id !== (int) $normalized['source_users_wallet_id']
            || $existing->source_order_reference !== $normalized['source_order_reference']
            || $existing->source_business_reference !== $normalized['source_business_reference']
            || bccomp((string) $existing->source_amount, $normalized['source_amount'], 2) !== 0
            || $existing->destination_central_wallet_id !== $normalized['destination_central_wallet_id'];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{status: int, body: array<string, mixed>}
     */
    private function abort(
        CentralWalletBalanceMigration $migration,
        string $failureCode,
        array $details,
        string $correlationId,
        string $actorId,
    ): array {
        if ($migration->status === BalanceMigrationStatus::Prepared) {
            $this->spokeClient->releaseLock(
                migrationOperationId: $migration->migration_operation_id,
                sourceSiteCode: $migration->source_site_code,
                sourceUsersWalletId: $migration->source_users_wallet_id,
            );
        }

        $migration = $this->transition($migration, BalanceMigrationStatus::Aborted, [
            'failure_code' => $failureCode,
            'failure_message' => json_encode($details, JSON_THROW_ON_ERROR),
            'aborted_at' => now(),
        ]);

        $this->auditEvents->record(
            eventType: 'migration.aborted',
            centralWalletId: $migration->destination_central_wallet_id,
            actorType: AuditActorType::Service,
            actorId: $actorId,
            correlationId: $correlationId,
            payload: [
                'migration_operation_id' => $migration->migration_operation_id,
                'failure_code' => $failureCode,
            ],
        );

        return $this->error($failureCode, 422, $migration);
    }

    private function executionEnabled(): bool
    {
        return (bool) config('central_wallet.balance_migration.execution_enabled', false);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function successResponse(CentralWalletBalanceMigration $migration, bool $replay): array
    {
        return [
            'status' => $replay ? 200 : 201,
            'body' => [
                'migration_operation_id' => $migration->migration_operation_id,
                'status' => $migration->status->value,
                'idempotency_key' => $migration->idempotency_key,
                'destination_central_wallet_id' => $migration->destination_central_wallet_id,
                'destination_ledger_entry_id' => $migration->destination_ledger_entry_id,
                'source_retirement_reference' => $migration->source_retirement_reference,
                'idempotent_replay' => $replay,
            ],
        ];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function error(
        string $code,
        int $status,
        ?CentralWalletBalanceMigration $migration = null,
    ): array {
        $body = ['error' => $code];
        if ($migration !== null) {
            $body['migration_operation_id'] = $migration->migration_operation_id;
            $body['migration_status'] = $migration->status->value;
        }

        return ['status' => $status, 'body' => $body];
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? '';

        return in_array($sqlState, ['23000', '23505'], true);
    }
}
