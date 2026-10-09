<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\Contracts\WalletMigrationSpokeClient;
use App\CentralWallet\Domain\Enums\BalanceMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletBalanceMigration;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeWalletMigrationSpokeClient;
use Tests\TestCase;

class BalanceMigrationCutoverTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-test-integration-token';

    private FakeWalletMigrationSpokeClient $spoke;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spoke = new FakeWalletMigrationSpokeClient;
        $this->app->instance(WalletMigrationSpokeClient::class, $this->spoke);

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
            'central_wallet.balance_migration.execution_enabled' => true,
        ]);
    }

    public function test_execution_disabled_returns_403(): void
    {
        config(['central_wallet.balance_migration.execution_enabled' => false]);

        $this->authenticated()->postJson('/api/central-wallet/v1/balance-migrations/execute', $this->payload())
            ->assertForbidden()
            ->assertJsonPath('error', 'migration_execution_disabled');
    }

    public function test_missing_owner_approval_returns_403(): void
    {
        $payload = $this->payload();
        $payload['owner_approval_ref'] = '';

        $this->authenticated()->postJson('/api/central-wallet/v1/balance-migrations/execute', $payload)
            ->assertForbidden()
            ->assertJsonPath('error', 'owner_approval_required');
    }

    public function test_successful_migration_end_to_end(): void
    {
        $cwid = $this->createWallet();

        $response = $this->authenticated()->postJson(
            '/api/central-wallet/v1/balance-migrations/execute',
            $this->payload($cwid),
        );

        $response->assertCreated()
            ->assertJsonPath('status', BalanceMigrationStatus::Reconciled->value)
            ->assertJsonPath('destination_central_wallet_id', $cwid);

        $this->assertDatabaseCount('central_wallet_ledger_entries', 1);
        $this->assertDatabaseHas('central_wallet_ledger_entries', [
            'central_wallet_id' => $cwid,
            'entry_type' => 'credit',
            'amount' => '100.00',
            'source_system' => 'rdservice.in',
            'source_reference' => 'users_wallet:99001',
            'business_reference' => 'REF-TEST-000001',
        ]);

        $this->assertCount(1, $this->spoke->lockCalls);
        $this->assertCount(1, $this->spoke->retireCalls);
    }

    public function test_idempotent_retry_does_not_double_credit(): void
    {
        $cwid = $this->createWallet();
        $payload = $this->payload($cwid);

        $this->authenticated()->postJson('/api/central-wallet/v1/balance-migrations/execute', $payload)
            ->assertCreated();

        $retry = $this->authenticated()->postJson('/api/central-wallet/v1/balance-migrations/execute', $payload);
        $retry->assertSuccessful();
        $this->assertTrue(
            $retry->json('idempotent_replay') === true
            || $retry->json('status') === BalanceMigrationStatus::Reconciled->value,
        );

        $this->assertSame(1, CentralWalletLedgerEntry::query()->count());
        $this->assertSame(1, CentralWalletBalanceMigration::query()->count());
    }

    public function test_duplicate_idempotency_key_with_different_payload_returns_409(): void
    {
        $cwid = $this->createWallet();
        $payload = $this->payload($cwid);

        $this->authenticated()->postJson('/api/central-wallet/v1/balance-migrations/execute', $payload)
            ->assertCreated();

        $payload['source_amount'] = '200.00';

        $this->authenticated()->postJson('/api/central-wallet/v1/balance-migrations/execute', $payload)
            ->assertStatus(409)
            ->assertJsonPath('error', 'idempotency_key_reused_with_different_request');
    }

    public function test_source_retirement_failure_enters_compensating_without_double_credit(): void
    {
        $this->spoke->retireShouldFail = true;
        $cwid = $this->createWallet();

        $this->authenticated()->postJson('/api/central-wallet/v1/balance-migrations/execute', $this->payload($cwid))
            ->assertStatus(502)
            ->assertJsonPath('error', 'source_retirement_failed')
            ->assertJsonPath('migration_status', BalanceMigrationStatus::Compensating->value);

        $this->assertSame(1, CentralWalletLedgerEntry::query()->count());
    }

    public function test_lock_failure_aborts_without_ledger_credit(): void
    {
        $this->spoke->lockShouldFail = true;
        $cwid = $this->createWallet();

        $this->authenticated()->postJson('/api/central-wallet/v1/balance-migrations/execute', $this->payload($cwid))
            ->assertStatus(422)
            ->assertJsonPath('error', 'spoke_lock_failed');

        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
        $this->assertDatabaseHas('central_wallet_balance_migrations', [
            'status' => BalanceMigrationStatus::Aborted->value,
        ]);
    }

    public function test_aborted_migration_idempotency_replay_is_not_successful(): void
    {
        $cwid = $this->createWallet();
        $payload = $this->payload($cwid);
        $payload['idempotency_key'] = 'aborted-replay-test';

        $this->spoke->lockShouldFail = true;
        $this->authenticated()->postJson('/api/central-wallet/v1/balance-migrations/execute', $payload)
            ->assertStatus(422);

        $this->spoke->lockShouldFail = false;
        $this->authenticated()->postJson('/api/central-wallet/v1/balance-migrations/execute', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error', 'spoke_lock_failed');

        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
    }

    public function test_wrong_destination_cwid_returns_422(): void
    {
        $this->createWallet();

        $this->authenticated()->postJson('/api/central-wallet/v1/balance-migrations/execute', $this->payload(
            '00000000-0000-4000-8000-000000000099',
        ))->assertStatus(422)
            ->assertJsonPath('error', 'destination_wallet_not_found');
    }

    public function test_reconciliation_failure_blocks_terminal_state(): void
    {
        $this->spoke->reconciliationShouldFail = true;
        $cwid = $this->createWallet();

        $this->authenticated()->postJson('/api/central-wallet/v1/balance-migrations/execute', $this->payload($cwid))
            ->assertStatus(502)
            ->assertJsonPath('error', 'source_reconciliation_failed')
            ->assertJsonPath('migration_status', BalanceMigrationStatus::SourceRetired->value);
    }

    public function test_ledger_credit_metadata_preserves_provenance(): void
    {
        $cwid = $this->createWallet();

        $this->authenticated()->postJson('/api/central-wallet/v1/balance-migrations/execute', $this->payload($cwid))
            ->assertCreated();

        $entry = CentralWalletLedgerEntry::query()->firstOrFail();
        $metadata = $entry->metadata;

        $this->assertSame('provenance_verified_balance_migration', $metadata['migration_type']);
        $this->assertSame('77', $metadata['source_local_user_id']);
        $this->assertSame('RD-TEST-0001', $metadata['source_order_reference']);
        $this->assertSame('OWNER-APPROVAL-TEST-001', $metadata['owner_approval_ref']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?string $cwid = null): array
    {
        $cwid ??= $this->createWallet();

        return [
            'owner_approval_ref' => 'OWNER-APPROVAL-TEST-001',
            'source_site_code' => 'rdservice.in',
            'source_local_user_id' => '77',
            'source_users_wallet_id' => 99001,
            'source_order_reference' => 'RD-TEST-0001',
            'source_business_reference' => 'REF-TEST-000001',
            'source_amount' => '100.00',
            'source_currency' => 'INR',
            'destination_central_wallet_id' => $cwid,
        ];
    }

    private function createWallet(): string
    {
        $response = $this->authenticated()->postJson('/api/central-wallet/v1/wallets', [
            'idempotency_key' => 'wallet-'.uniqid('', true),
        ]);

        return (string) $response->json('central_wallet_id');
    }

    private function authenticated(): static
    {
        return $this->withToken(self::TOKEN);
    }
}
