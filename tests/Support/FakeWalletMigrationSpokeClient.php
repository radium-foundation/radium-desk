<?php

namespace Tests\Support;

use App\CentralWallet\Application\Contracts\WalletMigrationSpokeClient;

final class FakeWalletMigrationSpokeClient implements WalletMigrationSpokeClient
{
    public bool $lockShouldFail = false;

    public bool $retireShouldFail = false;

    public bool $unavailable = false;

    public bool $reconciliationShouldFail = false;

    /** @var list<array<string, mixed>> */
    public array $lockCalls = [];

    /** @var list<array<string, mixed>> */
    public array $retireCalls = [];

    /** @var list<array<string, mixed>> */
    public array $releaseCalls = [];

    /** @var list<array<string, mixed>> */
    public array $restoreCalls = [];

    public bool $restoreShouldFail = false;

    /** When set, getMigrationStatus returns this lock_status (e.g. active). */
    public ?string $statusLockStatus = null;

    /** When set, getMigrationStatus returns this HTTP status (body is minimal error stub). */
    public ?int $statusHttpCode = null;

    /** When true, getMigrationStatus returns 200 without lock_status in the body. */
    public bool $statusOmitLockStatus = false;

    public function acquireLock(
        string $migrationOperationId,
        string $sourceSiteCode,
        string $sourceLocalUserId,
        int $sourceUsersWalletId,
        string $amount,
        string $sourceBusinessReference,
    ): array {
        $this->lockCalls[] = compact(
            'migrationOperationId',
            'sourceSiteCode',
            'sourceLocalUserId',
            'sourceUsersWalletId',
            'amount',
            'sourceBusinessReference',
        );

        if ($this->unavailable) {
            return ['status' => 503, 'body' => ['error' => 'migration_spoke_unavailable']];
        }

        if ($this->lockShouldFail) {
            return ['status' => 409, 'body' => ['error' => 'migration_lock_conflict']];
        }

        return ['status' => 200, 'body' => ['lock_status' => 'acquired']];
    }

    public function releaseLock(
        string $migrationOperationId,
        string $sourceSiteCode,
        int $sourceUsersWalletId,
    ): array {
        $this->releaseCalls[] = compact('migrationOperationId', 'sourceSiteCode', 'sourceUsersWalletId');

        return ['status' => 200, 'body' => ['lock_status' => 'released']];
    }

    public function retireSource(
        string $migrationOperationId,
        string $sourceSiteCode,
        string $sourceLocalUserId,
        int $sourceUsersWalletId,
        string $amount,
        string $sourceBusinessReference,
        string $retirementIdempotencyKey,
    ): array {
        $this->retireCalls[] = compact(
            'migrationOperationId',
            'sourceSiteCode',
            'sourceLocalUserId',
            'sourceUsersWalletId',
            'amount',
            'sourceBusinessReference',
            'retirementIdempotencyKey',
        );

        if ($this->unavailable) {
            return ['status' => 503, 'body' => ['error' => 'migration_spoke_unavailable']];
        }

        if ($this->retireShouldFail) {
            return ['status' => 502, 'body' => ['error' => 'retirement_failed']];
        }

        return ['status' => 201, 'body' => ['wallet_transaction_id' => '9001']];
    }

    public function getMigrationStatus(
        string $migrationOperationId,
        string $sourceSiteCode,
        string $sourceLocalUserId,
        int $sourceUsersWalletId,
        string $amount,
        string $sourceBusinessReference,
    ): array {
        if ($this->statusHttpCode !== null) {
            return [
                'status' => $this->statusHttpCode,
                'body' => ['error' => 'migration_status_stub'],
            ];
        }

        if ($this->statusOmitLockStatus) {
            return [
                'status' => 200,
                'body' => [
                    'migration_operation_id' => $migrationOperationId,
                ],
            ];
        }

        $lockStatus = $this->statusLockStatus ?? 'none';

        return [
            'status' => 200,
            'body' => [
                'migration_operation_id' => $migrationOperationId,
                'lock_status' => $lockStatus,
                'source_retired' => $lockStatus === 'retired',
            ],
        ];
    }

    public function verifyReconciliation(
        string $migrationOperationId,
        string $sourceSiteCode,
        string $sourceLocalUserId,
        int $sourceUsersWalletId,
        string $amount,
        string $sourceBusinessReference,
        string $retirementReference,
    ): array {
        if ($this->reconciliationShouldFail) {
            return ['status' => 409, 'body' => ['verified' => false]];
        }

        return [
            'status' => 200,
            'body' => [
                'verified' => true,
                'migration_operation_id' => $migrationOperationId,
            ],
        ];
    }

    public function restoreSourceCredit(
        string $migrationOperationId,
        string $sourceSiteCode,
        string $sourceLocalUserId,
        int $sourceUsersWalletId,
        string $amount,
        string $sourceBusinessReference,
        string $rollbackIdempotencyKey,
    ): array {
        $this->restoreCalls[] = compact(
            'migrationOperationId',
            'sourceSiteCode',
            'sourceLocalUserId',
            'sourceUsersWalletId',
            'amount',
            'sourceBusinessReference',
            'rollbackIdempotencyKey',
        );

        if ($this->restoreShouldFail) {
            return ['status' => 502, 'body' => ['error' => 'restore_failed']];
        }

        return ['status' => 201, 'body' => ['wallet_transaction_id' => '9002']];
    }
}
