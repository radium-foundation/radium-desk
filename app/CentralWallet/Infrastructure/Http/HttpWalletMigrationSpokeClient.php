<?php

namespace App\CentralWallet\Infrastructure\Http;

use App\CentralWallet\Application\Contracts\WalletMigrationSpokeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

final class HttpWalletMigrationSpokeClient implements WalletMigrationSpokeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
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
        return $this->post('/api/integrations/v1/wallet-migration-locks/acquire', [
            'migration_operation_id' => $migrationOperationId,
            'users_wallet_id' => $sourceUsersWalletId,
            'userid' => $sourceLocalUserId,
            'amount' => $amount,
            'desk_refund_reference' => $sourceBusinessReference,
            'source_site_code' => $sourceSiteCode,
        ], $migrationOperationId);
    }

    public function releaseLock(
        string $migrationOperationId,
        string $sourceSiteCode,
        int $sourceUsersWalletId,
    ): array {
        return $this->post('/api/integrations/v1/wallet-migration-locks/release', [
            'migration_operation_id' => $migrationOperationId,
            'users_wallet_id' => $sourceUsersWalletId,
            'source_site_code' => $sourceSiteCode,
        ], $migrationOperationId);
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
        return $this->post('/api/integrations/v1/wallet-migration-retirements', [
            'migration_operation_id' => $migrationOperationId,
            'users_wallet_id' => $sourceUsersWalletId,
            'userid' => $sourceLocalUserId,
            'amount' => $amount,
            'desk_refund_reference' => $sourceBusinessReference,
            'idempotency_key' => $retirementIdempotencyKey,
            'source_site_code' => $sourceSiteCode,
        ], $migrationOperationId);
    }

    public function getMigrationStatus(
        string $migrationOperationId,
        string $sourceSiteCode,
        string $sourceLocalUserId,
        int $sourceUsersWalletId,
        string $amount,
        string $sourceBusinessReference,
    ): array {
        return $this->post('/api/integrations/v1/wallet-migration-status', [
            'intent' => 'status',
            'migration_operation_id' => $migrationOperationId,
            'users_wallet_id' => $sourceUsersWalletId,
            'userid' => $sourceLocalUserId,
            'amount' => $amount,
            'desk_refund_reference' => $sourceBusinessReference,
            'source_site_code' => $sourceSiteCode,
        ], $migrationOperationId);
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
        return $this->post('/api/integrations/v1/wallet-migration-status', [
            'intent' => 'verify_reconciliation',
            'migration_operation_id' => $migrationOperationId,
            'users_wallet_id' => $sourceUsersWalletId,
            'userid' => $sourceLocalUserId,
            'amount' => $amount,
            'desk_refund_reference' => $sourceBusinessReference,
            'source_site_code' => $sourceSiteCode,
            'retirement_wallet_transaction_id' => $retirementReference,
        ], $migrationOperationId);
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
        return $this->post('/api/integrations/v1/wallet-migration-restorations', [
            'migration_operation_id' => $migrationOperationId,
            'users_wallet_id' => $sourceUsersWalletId,
            'userid' => $sourceLocalUserId,
            'amount' => $amount,
            'desk_refund_reference' => $sourceBusinessReference,
            'idempotency_key' => $rollbackIdempotencyKey,
            'source_site_code' => $sourceSiteCode,
        ], $migrationOperationId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    private function post(string $path, array $payload, string $migrationOperationId): array
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->acceptJson()
                ->withToken($this->token)
                ->withHeaders([
                    'X-Migration-Operation-Id' => $migrationOperationId,
                ])
                ->connectTimeout($this->connectTimeoutSeconds)
                ->timeout($this->timeoutSeconds)
                ->post($path, $payload);

            $body = $response->json();
            if (! is_array($body)) {
                return $this->unknownResponse('migration_spoke_malformed_response');
            }

            if (in_array($response->status(), [401, 403], true)) {
                return [
                    'status' => $response->status(),
                    'body' => array_merge($body, ['outcome' => 'error']),
                ];
            }

            return [
                'status' => $response->status(),
                'body' => $body,
            ];
        } catch (ConnectionException) {
            return $this->unknownResponse('migration_spoke_timeout');
        } catch (RequestException $exception) {
            $response = $exception->response;
            if ($response !== null) {
                $body = $response->json();
                if (is_array($body)) {
                    return [
                        'status' => $response->status(),
                        'body' => $body,
                    ];
                }
            }

            return $this->unknownResponse('migration_spoke_unknown');
        }
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function unknownResponse(string $error): array
    {
        return [
            'status' => 503,
            'body' => [
                'error' => $error,
                'outcome' => 'unknown',
            ],
        ];
    }
}
