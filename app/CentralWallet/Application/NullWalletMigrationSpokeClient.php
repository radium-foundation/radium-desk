<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Application\Contracts\WalletMigrationSpokeClient;

/**
 * Fail-closed spoke client used when migration spoke integration is not configured.
 */
final class NullWalletMigrationSpokeClient implements WalletMigrationSpokeClient
{
    public function acquireLock(
        string $migrationOperationId,
        string $sourceSiteCode,
        string $sourceLocalUserId,
        int $sourceUsersWalletId,
        string $amount,
        string $sourceBusinessReference,
    ): array {
        return [
            'status' => 503,
            'body' => ['error' => 'migration_spoke_unavailable'],
        ];
    }

    public function releaseLock(
        string $migrationOperationId,
        string $sourceSiteCode,
        int $sourceUsersWalletId,
    ): array {
        return [
            'status' => 503,
            'body' => ['error' => 'migration_spoke_unavailable'],
        ];
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
        return [
            'status' => 503,
            'body' => ['error' => 'migration_spoke_unavailable'],
        ];
    }

    public function getMigrationStatus(
        string $migrationOperationId,
        string $sourceSiteCode,
        string $sourceLocalUserId,
        int $sourceUsersWalletId,
        string $amount,
        string $sourceBusinessReference,
    ): array {
        return [
            'status' => 503,
            'body' => ['error' => 'migration_spoke_unavailable'],
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
        return [
            'status' => 503,
            'body' => ['error' => 'migration_spoke_unavailable'],
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
        return [
            'status' => 503,
            'body' => ['error' => 'migration_spoke_unavailable'],
        ];
    }
}
