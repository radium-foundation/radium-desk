<?php

namespace Tests\Support;

use App\CentralWallet\Application\Contracts\WalletMigrationSpokeClient;

final class FakeWalletMigrationSpokeClient implements WalletMigrationSpokeClient
{
    public bool $lockShouldFail = false;

    public bool $retireShouldFail = false;

    public bool $unavailable = false;

    /** @var list<array<string, mixed>> */
    public array $lockCalls = [];

    /** @var list<array<string, mixed>> */
    public array $retireCalls = [];

    /** @var list<array<string, mixed>> */
    public array $releaseCalls = [];

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
}
