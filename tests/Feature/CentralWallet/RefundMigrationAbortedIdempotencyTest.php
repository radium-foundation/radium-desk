<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\Contracts\WalletMigrationSpokeClient;
use App\CentralWallet\Application\PilotRefundMigrationJournalImportService;
use App\CentralWallet\Application\RefundMigrationFalseReconcileRepairService;
use App\CentralWallet\Application\RefundMigrationLane1Executor;
use App\CentralWallet\Domain\Enums\BalanceMigrationStatus;
use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Domain\RefundMigrationIdempotencyKey;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletBalanceMigration;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\RefundRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Support\FakeWalletMigrationSpokeClient;
use Tests\TestCase;

class RefundMigrationAbortedIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private FakeWalletMigrationSpokeClient $spoke;

    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->manifestPath = base_path('tests/fixtures/cw-pilot-refund-ref-67392-manifest.json');

        $this->spoke = new FakeWalletMigrationSpokeClient;
        $this->app->instance(WalletMigrationSpokeClient::class, $this->spoke);

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => 'cw-test-integration-token',
            'central_wallet.balance_migration.execution_enabled' => true,
            'central_wallet.refund_migration.execution_enabled' => true,
        ]);
    }

    public function test_aborted_balance_migration_idempotency_replay_returns_failure_not_success(): void
    {
        $cwid = $this->createWallet();
        $idempotencyKey = 'desk-refund-migration:refund_requests:387';

        $this->createBalanceMigrationRow([
            'idempotency_key' => $idempotencyKey,
            'status' => BalanceMigrationStatus::Aborted,
            'failure_code' => 'spoke_lock_failed',
            'source_local_user_id' => '562976',
            'source_users_wallet_id' => 2663,
            'source_order_reference' => 'RD16854',
            'source_business_reference' => 'REF-67392',
            'source_amount' => '499.00',
            'destination_central_wallet_id' => $cwid,
            'migration_batch_id' => 'pilot-batch',
        ]);

        $response = $this->withToken('cw-test-integration-token')->postJson(
            '/api/central-wallet/v1/balance-migrations/execute',
            $this->balancePayload($cwid, $idempotencyKey, [
                'source_local_user_id' => '562976',
                'source_users_wallet_id' => 2663,
                'source_order_reference' => 'RD16854',
                'source_business_reference' => 'REF-67392',
                'source_amount' => '499.00',
            ]),
        );

        $response->assertStatus(422)
            ->assertJsonPath('error', 'spoke_lock_failed');

        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
        $this->assertSame(0, count($this->spoke->lockCalls));
    }

    public function test_reconciled_balance_migration_without_evidence_blocks_idempotent_replay(): void
    {
        $cwid = $this->createWallet();
        $idempotencyKey = 'replay-without-evidence';

        $this->createBalanceMigrationRow([
            'idempotency_key' => $idempotencyKey,
            'status' => BalanceMigrationStatus::Reconciled,
            'source_local_user_id' => '77',
            'source_users_wallet_id' => 99001,
            'source_order_reference' => 'RD-TEST-0001',
            'source_business_reference' => 'REF-TEST-000001',
            'source_amount' => '100.00',
            'destination_central_wallet_id' => $cwid,
            'migration_batch_id' => 'batch',
        ]);

        $this->withToken('cw-test-integration-token')->postJson(
            '/api/central-wallet/v1/balance-migrations/execute',
            $this->balancePayload($cwid, $idempotencyKey),
        )->assertStatus(409)
            ->assertJsonPath('error', 'migration_reconciled_without_financial_evidence');
    }

    public function test_lane1_does_not_reconcile_when_aborted_balance_migration_replays(): void
    {
        $customer = $this->seedRef67392LiveData();
        app(PilotRefundMigrationJournalImportService::class)->import($this->manifestPath);

        $idempotencyKey = RefundMigrationIdempotencyKey::forRefund(387);
        $this->createBalanceMigrationRow([
            'migration_operation_id' => 'bf9f4dda-2dd3-48fc-9e0c-eb9127a8c941',
            'idempotency_key' => $idempotencyKey,
            'status' => BalanceMigrationStatus::Aborted,
            'failure_code' => 'spoke_lock_failed',
            'source_local_user_id' => '562976',
            'source_users_wallet_id' => 2663,
            'source_order_reference' => 'RD16854',
            'source_business_reference' => 'REF-67392',
            'source_amount' => '499.00',
            'destination_central_wallet_id' => $customer->central_wallet_id,
            'migration_batch_id' => 'pilot',
        ]);

        $migration = CentralWalletRefundMigration::query()->where('refund_id', 387)->firstOrFail();

        try {
            app(RefundMigrationLane1Executor::class)->execute(
                $migration,
                'OWNER-PILOT-TEST',
                (string) Str::uuid(),
                'test',
            );
            $this->fail('Expected spoke_lock_failed');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('spoke_lock_failed', $exception->getMessage());
        }

        $fresh = $migration->fresh();
        $this->assertSame(RefundMigrationStatus::Failed->value, $fresh->status->value);
        $this->assertNotSame(RefundMigrationStatus::Reconciled->value, $fresh->status->value);
        $this->assertNull($fresh->destination_ledger_entry_id);
        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
    }

    public function test_false_reconcile_repair_moves_migration_to_reconciliation_required(): void
    {
        $cwid = '8946cd5e-208d-420f-b922-f5727bc47a87';
        CentralWallet::query()->forceCreate([
            'id' => $cwid,
            'status' => 'active',
        ]);

        CentralCustomer::query()->create([
            'id' => '90f0b408-f874-40e1-927b-6ce41cee0aa9',
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);

        $this->createBalanceMigrationRow([
            'migration_operation_id' => 'bf9f4dda-2dd3-48fc-9e0c-eb9127a8c941',
            'idempotency_key' => RefundMigrationIdempotencyKey::forRefund(387),
            'status' => BalanceMigrationStatus::Aborted,
            'failure_code' => 'spoke_lock_failed',
            'source_local_user_id' => '562976',
            'source_users_wallet_id' => 2663,
            'source_order_reference' => 'RD16854',
            'source_business_reference' => 'REF-67392',
            'source_amount' => '499.00',
            'destination_central_wallet_id' => $cwid,
            'migration_batch_id' => 'pilot',
        ]);

        CentralWalletRefundMigration::query()->create([
            'id' => (string) Str::uuid(),
            'batch_id' => 'pilot',
            'refund_id' => 387,
            'refund_reference' => 'REF-67392',
            'amount' => '499.00',
            'source_type' => 'spoke_wallet',
            'source_application' => 'rdservice.in',
            'source_wallet_id' => 2663,
            'source_reference' => RefundMigrationIdempotencyKey::ledgerSourceReference(387),
            'desk_customer_id' => '90f0b408-f874-40e1-927b-6ce41cee0aa9',
            'cwid' => $cwid,
            'lane' => RefundMigrationLane::Lane1SpokeCutover,
            'status' => RefundMigrationStatus::Reconciled,
            'idempotency_key' => RefundMigrationIdempotencyKey::forRefund(387),
            'balance_migration_operation_id' => 'bf9f4dda-2dd3-48fc-9e0c-eb9127a8c941',
            'executed_at' => now(),
        ]);

        config(['central_wallet.pilot_refund_migration.allowed_refund_ids' => '387']);

        $result = app(RefundMigrationFalseReconcileRepairService::class)->repair(
            387,
            (string) Str::uuid(),
            'test',
        );

        $this->assertSame(RefundMigrationStatus::ReconciliationRequired->value, $result['status']);
        $this->assertNull($result['destination_ledger_entry_id']);
        $this->assertNull($result['source_debit_reference']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createBalanceMigrationRow(array $overrides): CentralWalletBalanceMigration
    {
        return CentralWalletBalanceMigration::query()->create(array_merge([
            'migration_operation_id' => (string) Str::uuid(),
            'idempotency_key' => 'bm-'.Str::uuid(),
            'status' => BalanceMigrationStatus::Aborted,
            'source_site_code' => 'rdservice.in',
            'source_local_user_id' => '77',
            'source_users_wallet_id' => 99001,
            'source_order_reference' => 'RD-TEST-0001',
            'source_business_reference' => 'REF-TEST-000001',
            'source_amount' => '100.00',
            'source_currency' => 'INR',
            'destination_central_wallet_id' => (string) Str::uuid(),
            'migration_batch_id' => 'batch',
            'correlation_id' => (string) Str::uuid(),
            'destination_ledger_entry_id' => null,
            'source_retirement_reference' => null,
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function balancePayload(string $cwid, string $idempotencyKey, array $overrides = []): array
    {
        return array_merge([
            'owner_approval_ref' => 'OWNER-APPROVAL-TEST-001',
            'source_site_code' => 'rdservice.in',
            'source_local_user_id' => '77',
            'source_users_wallet_id' => 99001,
            'source_order_reference' => 'RD-TEST-0001',
            'source_business_reference' => 'REF-TEST-000001',
            'source_amount' => '100.00',
            'source_currency' => 'INR',
            'destination_central_wallet_id' => $cwid,
            'idempotency_key' => $idempotencyKey,
        ], $overrides);
    }

    private function createWallet(): string
    {
        $response = $this->withToken('cw-test-integration-token')->postJson('/api/central-wallet/v1/wallets', [
            'idempotency_key' => 'wallet-'.uniqid('', true),
        ]);

        return (string) $response->json('central_wallet_id');
    }

    private function seedRef67392LiveData(): CentralCustomer
    {
        $cwid = '8946cd5e-208d-420f-b922-f5727bc47a87';
        CentralWallet::query()->forceCreate([
            'id' => $cwid,
            'status' => 'active',
        ]);

        $customer = CentralCustomer::query()->create([
            'id' => '90f0b408-f874-40e1-927b-6ce41cee0aa9',
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);

        $orderId = 62567;
        DB::table('orders')->insert([
            'id' => $orderId,
            'order_id' => 'RD16854',
            'customer_id' => $customer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create();

        RefundRequest::query()->forceCreate([
            'id' => 387,
            'order_id' => $orderId,
            'reference_no' => 'REF-67392',
            'amount' => '499.00',
            'refund_amount' => '499.00',
            'reason' => 'Pilot migration test fixture.',
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'status' => RefundStatus::Closed,
            'execution_transaction_id' => '2663',
            'requested_by' => $user->id,
            'communication_channels' => [],
        ]);

        return $customer;
    }
}
