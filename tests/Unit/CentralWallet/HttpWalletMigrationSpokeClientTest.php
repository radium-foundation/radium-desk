<?php

namespace Tests\Unit\CentralWallet;

use App\CentralWallet\Infrastructure\Http\HttpWalletMigrationSpokeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpWalletMigrationSpokeClientTest extends TestCase
{
    private const BASE_URL = 'https://rdservice.in.test';

    private const TOKEN = 'desk-migration-token';

    private HttpWalletMigrationSpokeClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new HttpWalletMigrationSpokeClient(
            baseUrl: self::BASE_URL,
            token: self::TOKEN,
            connectTimeoutSeconds: 1,
            timeoutSeconds: 2,
        );
    }

    public function test_acquire_lock_success(): void
    {
        Http::fake([
            self::BASE_URL.'/api/integrations/v1/wallet-migration-locks/acquire' => Http::response([
                'migration_operation_id' => 'op-1',
                'lock_status' => 'active',
            ], 201),
        ]);

        $result = $this->client->acquireLock(
            migrationOperationId: 'op-1',
            sourceSiteCode: 'rdservice.in',
            sourceLocalUserId: '77',
            sourceUsersWalletId: 99001,
            amount: '100.00',
            sourceBusinessReference: 'REF-TEST-000001',
        );

        $this->assertSame(201, $result['status']);
        $this->assertSame('active', $result['body']['lock_status']);

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && $request->hasHeader('X-Migration-Operation-Id', 'op-1')
                && $request['users_wallet_id'] === 99001
                && $request['userid'] === '77';
        });
    }

    public function test_lock_rejection_propagates_status(): void
    {
        Http::fake([
            self::BASE_URL.'/api/integrations/v1/wallet-migration-locks/acquire' => Http::response([
                'error' => 'migration_lock_conflict',
            ], 409),
        ]);

        $result = $this->client->acquireLock(
            migrationOperationId: 'op-2',
            sourceSiteCode: 'rdservice.in',
            sourceLocalUserId: '77',
            sourceUsersWalletId: 99001,
            amount: '100.00',
            sourceBusinessReference: 'REF-TEST-000001',
        );

        $this->assertSame(409, $result['status']);
        $this->assertSame('migration_lock_conflict', $result['body']['error']);
    }

    public function test_timeout_returns_unknown_outcome(): void
    {
        Http::fake(function (): void {
            throw new ConnectionException('timeout');
        });

        $result = $this->client->acquireLock(
            migrationOperationId: 'op-3',
            sourceSiteCode: 'rdservice.in',
            sourceLocalUserId: '77',
            sourceUsersWalletId: 99001,
            amount: '100.00',
            sourceBusinessReference: 'REF-TEST-000001',
        );

        $this->assertSame(503, $result['status']);
        $this->assertSame('migration_spoke_timeout', $result['body']['error']);
        $this->assertSame('unknown', $result['body']['outcome']);
    }

    public function test_authentication_failure_is_fail_closed(): void
    {
        Http::fake([
            self::BASE_URL.'/api/integrations/v1/wallet-migration-locks/acquire' => Http::response([
                'message' => 'Unauthenticated.',
            ], 401),
        ]);

        $result = $this->client->acquireLock(
            migrationOperationId: 'op-4',
            sourceSiteCode: 'rdservice.in',
            sourceLocalUserId: '77',
            sourceUsersWalletId: 99001,
            amount: '100.00',
            sourceBusinessReference: 'REF-TEST-000001',
        );

        $this->assertSame(401, $result['status']);
        $this->assertSame('error', $result['body']['outcome']);
    }

    public function test_malformed_response_returns_unknown(): void
    {
        Http::fake([
            self::BASE_URL.'/api/integrations/v1/wallet-migration-retirements' => Http::response('not-json', 200),
        ]);

        $result = $this->client->retireSource(
            migrationOperationId: 'op-5',
            sourceSiteCode: 'rdservice.in',
            sourceLocalUserId: '77',
            sourceUsersWalletId: 99001,
            amount: '100.00',
            sourceBusinessReference: 'REF-TEST-000001',
            retirementIdempotencyKey: 'retire-key',
        );

        $this->assertSame(503, $result['status']);
        $this->assertSame('migration_spoke_malformed_response', $result['body']['error']);
    }

    public function test_retire_source_sends_idempotency_key(): void
    {
        Http::fake([
            self::BASE_URL.'/api/integrations/v1/wallet-migration-retirements' => Http::response([
                'wallet_transaction_id' => '9001',
            ], 201),
        ]);

        $this->client->retireSource(
            migrationOperationId: 'op-6',
            sourceSiteCode: 'rdservice.in',
            sourceLocalUserId: '77',
            sourceUsersWalletId: 99001,
            amount: '100.00',
            sourceBusinessReference: 'REF-TEST-000001',
            retirementIdempotencyKey: 'rdin-migration-retire:users_wallet:99001',
        );

        Http::assertSent(function ($request): bool {
            return $request['idempotency_key'] === 'rdin-migration-retire:users_wallet:99001';
        });
    }

    public function test_verify_reconciliation_uses_status_endpoint(): void
    {
        Http::fake([
            self::BASE_URL.'/api/integrations/v1/wallet-migration-status' => Http::response([
                'verified' => true,
            ], 200),
        ]);

        $result = $this->client->verifyReconciliation(
            migrationOperationId: 'op-7',
            sourceSiteCode: 'rdservice.in',
            sourceLocalUserId: '77',
            sourceUsersWalletId: 99001,
            amount: '100.00',
            sourceBusinessReference: 'REF-TEST-000001',
            retirementReference: '9001',
        );

        $this->assertTrue($result['body']['verified']);
        Http::assertSent(fn ($request): bool => $request['intent'] === 'verify_reconciliation');
    }

    public function test_release_lock_success(): void
    {
        Http::fake([
            self::BASE_URL.'/api/integrations/v1/wallet-migration-locks/release' => Http::response([
                'lock_status' => 'released',
            ], 200),
        ]);

        $result = $this->client->releaseLock(
            migrationOperationId: 'op-8',
            sourceSiteCode: 'rdservice.in',
            sourceUsersWalletId: 99001,
        );

        $this->assertSame(200, $result['status']);
        $this->assertSame('released', $result['body']['lock_status']);
    }
}
