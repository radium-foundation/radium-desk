<?php

namespace Tests\Unit\CentralWallet;

use App\CentralWallet\Application\IdempotencyService;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletIdempotencyRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdempotencyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_retention_defaults_to_ninety_days(): void
    {
        config(['central_wallet.idempotency_retention_days' => 90]);

        $service = app(IdempotencyService::class);

        $service->execute('caller', 'key-1', 'hash-a', fn (): array => [
            'status' => 201,
            'body' => ['ok' => true],
        ]);

        $record = CentralWalletIdempotencyRecord::query()->first();
        $this->assertNotNull($record);
        $this->assertTrue($record->expires_at->greaterThan(now()->addDays(89)));
    }

    public function test_replay_returns_full_original_response_body(): void
    {
        $service = app(IdempotencyService::class);

        $first = $service->execute('caller-a', 'key-replay', 'hash-same', fn (): array => [
            'status' => 201,
            'body' => [
                'ledger_entry_id' => 42,
                'amount' => '10.00',
                'currency' => 'INR',
            ],
            'resource_type' => 'ledger_entry',
            'resource_id' => '42',
        ]);

        $this->assertFalse($first['replay']);
        $this->assertSame(201, $first['status']);
        $this->assertSame('10.00', $first['body']['amount']);

        $second = $service->execute('caller-a', 'key-replay', 'hash-same', fn (): array => [
            'status' => 201,
            'body' => ['should_not_run' => true],
        ]);

        $this->assertTrue($second['replay']);
        $this->assertSame(201, $second['status']);
        $this->assertTrue($second['body']['idempotent_replay']);
        $this->assertSame(42, $second['body']['ledger_entry_id']);
        $this->assertSame('10.00', $second['body']['amount']);
        $this->assertSame('INR', $second['body']['currency']);
        $this->assertArrayNotHasKey('should_not_run', $second['body']);
    }

    public function test_different_request_hash_is_rejected(): void
    {
        $service = app(IdempotencyService::class);

        $service->execute('caller-a', 'key-conflict', 'hash-one', fn (): array => [
            'status' => 201,
            'body' => ['ok' => true],
        ]);

        $replay = $service->execute('caller-a', 'key-conflict', 'hash-two', fn (): array => [
            'status' => 201,
            'body' => ['ok' => true],
        ]);

        $this->assertTrue($replay['replay']);
        $this->assertSame(409, $replay['status']);
        $this->assertSame('idempotency_key_reused_with_different_request', $replay['body']['error']);
    }

    public function test_idempotency_keys_are_isolated_by_caller(): void
    {
        $service = app(IdempotencyService::class);

        $service->execute('rdservice.in', 'shared-key', 'hash-a', fn (): array => [
            'status' => 201,
            'body' => ['site' => 'rdservice.in'],
        ]);

        $result = $service->execute('radiumbox.com', 'shared-key', 'hash-b', fn (): array => [
            'status' => 201,
            'body' => ['site' => 'radiumbox.com'],
        ]);

        $this->assertFalse($result['replay']);
        $this->assertSame('radiumbox.com', $result['body']['site']);
        $this->assertSame(2, CentralWalletIdempotencyRecord::query()->count());
    }

    public function test_sanitizes_sensitive_fields_before_storage(): void
    {
        $service = app(IdempotencyService::class);

        $service->execute('caller', 'sanitize-key', 'hash', fn (): array => [
            'status' => 201,
            'body' => [
                'token' => 'super-secret',
                'amount' => '1.00',
            ],
        ]);

        $record = CentralWalletIdempotencyRecord::query()->first();
        $this->assertSame('[REDACTED]', $record->response_body['token']);
        $this->assertSame('1.00', $record->response_body['amount']);
    }

    public function test_purge_expired_records(): void
    {
        CentralWalletIdempotencyRecord::query()->create([
            'caller_id' => 'caller',
            'idempotency_key' => 'expired',
            'request_hash' => 'hash',
            'response_status' => 201,
            'response_body' => ['ok' => true],
            'expires_at' => now()->subDay(),
        ]);

        $purged = app(IdempotencyService::class)->purgeExpired();

        $this->assertSame(1, $purged);
        $this->assertDatabaseCount('central_wallet_idempotency_records', 0);
    }
}
