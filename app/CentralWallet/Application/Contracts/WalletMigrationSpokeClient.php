<?php

namespace App\CentralWallet\Application\Contracts;

interface WalletMigrationSpokeClient
{
    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function acquireLock(
        string $migrationOperationId,
        string $sourceSiteCode,
        string $sourceLocalUserId,
        int $sourceUsersWalletId,
        string $amount,
        string $sourceBusinessReference,
    ): array;

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function releaseLock(
        string $migrationOperationId,
        string $sourceSiteCode,
        int $sourceUsersWalletId,
    ): array;

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function retireSource(
        string $migrationOperationId,
        string $sourceSiteCode,
        string $sourceLocalUserId,
        int $sourceUsersWalletId,
        string $amount,
        string $sourceBusinessReference,
        string $retirementIdempotencyKey,
    ): array;
}
