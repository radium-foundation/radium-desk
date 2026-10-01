<?php

namespace App\CentralWallet\Infrastructure\Http;

use App\CentralWallet\Application\Contracts\WalletMigrationSpokeClient;
use InvalidArgumentException;

final class RoutingWalletMigrationSpokeClient implements WalletMigrationSpokeClient
{
    /** @var array<string, HttpWalletMigrationSpokeClient> */
    private array $clients = [];

    /**
     * @param  array<string, array{base_url: string, token: string, host?: string|null}>  $spokes
     */
    public function __construct(
        private readonly array $spokes,
        private readonly int $connectTimeoutSeconds = 3,
        private readonly int $timeoutSeconds = 15,
    ) {}

    public function acquireLock(
        string $migrationOperationId,
        string $sourceSiteCode,
        string $sourceLocalUserId,
        int $sourceUsersWalletId,
        string $amount,
        string $sourceBusinessReference,
    ): array {
        return $this->clientFor($sourceSiteCode)->acquireLock(
            $migrationOperationId,
            $sourceSiteCode,
            $sourceLocalUserId,
            $sourceUsersWalletId,
            $amount,
            $sourceBusinessReference,
        );
    }

    public function releaseLock(
        string $migrationOperationId,
        string $sourceSiteCode,
        int $sourceUsersWalletId,
    ): array {
        return $this->clientFor($sourceSiteCode)->releaseLock(
            $migrationOperationId,
            $sourceSiteCode,
            $sourceUsersWalletId,
        );
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
        return $this->clientFor($sourceSiteCode)->retireSource(
            $migrationOperationId,
            $sourceSiteCode,
            $sourceLocalUserId,
            $sourceUsersWalletId,
            $amount,
            $sourceBusinessReference,
            $retirementIdempotencyKey,
        );
    }

    public function getMigrationStatus(
        string $migrationOperationId,
        string $sourceSiteCode,
        string $sourceLocalUserId,
        int $sourceUsersWalletId,
        string $amount,
        string $sourceBusinessReference,
    ): array {
        return $this->clientFor($sourceSiteCode)->getMigrationStatus(
            $migrationOperationId,
            $sourceSiteCode,
            $sourceLocalUserId,
            $sourceUsersWalletId,
            $amount,
            $sourceBusinessReference,
        );
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
        return $this->clientFor($sourceSiteCode)->verifyReconciliation(
            $migrationOperationId,
            $sourceSiteCode,
            $sourceLocalUserId,
            $sourceUsersWalletId,
            $amount,
            $sourceBusinessReference,
            $retirementReference,
        );
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
        return $this->clientFor($sourceSiteCode)->restoreSourceCredit(
            $migrationOperationId,
            $sourceSiteCode,
            $sourceLocalUserId,
            $sourceUsersWalletId,
            $amount,
            $sourceBusinessReference,
            $rollbackIdempotencyKey,
        );
    }

    private function clientFor(string $sourceSiteCode): HttpWalletMigrationSpokeClient
    {
        $site = trim($sourceSiteCode);
        if ($site === '') {
            throw new InvalidArgumentException('migration_spoke_site_required');
        }

        if (! isset($this->clients[$site])) {
            $config = $this->spokes[$site] ?? null;
            if (! is_array($config)) {
                throw new InvalidArgumentException('migration_spoke_not_configured:'.$site);
            }

            $baseUrl = rtrim(trim((string) ($config['base_url'] ?? '')), '/');
            $token = trim((string) ($config['token'] ?? ''));
            $hostHeader = trim((string) ($config['host'] ?? ''));
            if ($baseUrl === '' || $token === '') {
                throw new InvalidArgumentException('migration_spoke_not_configured:'.$site);
            }

            $this->clients[$site] = new HttpWalletMigrationSpokeClient(
                baseUrl: $baseUrl,
                token: $token,
                connectTimeoutSeconds: $this->connectTimeoutSeconds,
                timeoutSeconds: $this->timeoutSeconds,
                hostHeader: $hostHeader !== '' ? $hostHeader : null,
            );
        }

        return $this->clients[$site];
    }
}
