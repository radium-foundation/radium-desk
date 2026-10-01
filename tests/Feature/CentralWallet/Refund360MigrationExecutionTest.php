<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\BalanceMigrationCutoverService;
use App\CentralWallet\Application\Contracts\WalletMigrationSpokeClient;
use App\CentralWallet\Application\Refund360MigrationJournalImportService;
use App\CentralWallet\Application\Refund360MigrationManifestLoader;
use App\CentralWallet\Application\Refund360MigrationOrchestrator;
use App\CentralWallet\Application\RefundMigrationLane1Executor;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\FakeWalletMigrationSpokeClient;
use Tests\TestCase;

class Refund360MigrationExecutionTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_REF = Refund360MigrationManifestLoader::EXPECTED_OWNER_APPROVAL_REF;

    private FakeWalletMigrationSpokeClient $spoke;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->spoke = new FakeWalletMigrationSpokeClient;
        $this->app->instance(WalletMigrationSpokeClient::class, $this->spoke);
        $this->app->forgetInstance(RefundMigrationLane1Executor::class);
        $this->app->forgetInstance(BalanceMigrationCutoverService::class);
        $this->app->forgetInstance(Refund360MigrationOrchestrator::class);

        config([
            'central_wallet.enabled' => true,
            'central_wallet.refund360_migration.manifest_path' => base_path(
                'tests/fixtures/cw-refund360-migration-p30-10-26.json',
            ),
            'central_wallet.balance_migration.execution_enabled' => true,
            'central_wallet.refund_migration.execution_enabled' => true,
        ]);
    }

    public function test_orchestrator_rejects_non_refund360_batch_row(): void
    {
        $migration = CentralWalletRefundMigration::query()->create([
            'id' => '00000000-0000-4000-8000-000000000360',
            'batch_id' => 'desk-refund-wallet-migration-type1-ready4-p30-10-12',
            'refund_id' => 360,
            'refund_reference' => 'REF-67363',
            'amount' => '849.00',
            'source_type' => 'spoke_wallet',
            'source_application' => 'radiumbox.com',
            'source_wallet_id' => 2567,
            'source_reference' => 'desk-refund-migration:refund_requests:360',
            'lane' => \App\CentralWallet\Domain\Enums\RefundMigrationLane::Lane1SpokeCutover,
            'status' => RefundMigrationStatus::Prepared,
            'idempotency_key' => 'desk-refund-migration:refund_requests:360',
            'prepared_at' => now(),
            'metadata' => [],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not_refund360_migration_row');
        app(Refund360MigrationOrchestrator::class)->executeSingle($migration, self::OWNER_REF);
    }

    public function test_execute_refund_360_is_reconciled_and_idempotent(): void
    {
        $this->seedRefund360IdentityAndRefund();
        app(Refund360MigrationJournalImportService::class)->import();

        $orchestrator = app(Refund360MigrationOrchestrator::class);
        $migration = CentralWalletRefundMigration::query()->where('refund_id', 360)->firstOrFail();

        $result = $orchestrator->executeSingle($migration, self::OWNER_REF);
        $this->assertSame(RefundMigrationStatus::Reconciled->value, $result['status']);
        $this->assertSame('849.00', (string) $migration->fresh()->amount);

        $replay = $orchestrator->executeSingle($migration->fresh(), self::OWNER_REF);
        $this->assertTrue($replay['idempotent_replay'] ?? false);

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('entry_type', 'credit')->count());
        $this->assertSame(1, CentralWalletRefundMigration::query()->where('status', RefundMigrationStatus::Reconciled)->count());
        $this->assertCount(1, $this->spoke->retireCalls);
        $this->assertCount(1, $this->spoke->lockCalls);
    }

    private function seedRefund360IdentityAndRefund(): void
    {
        CentralWallet::query()->create([
            'id' => '5a3d0706-9f4b-4adc-b3d8-7cd134295404',
            'status' => 'active',
            'currency' => 'INR',
        ]);

        CentralCustomer::query()->create([
            'id' => 'be49594b-289c-44d6-b9e4-6c8d3a810227',
            'central_wallet_id' => '5a3d0706-9f4b-4adc-b3d8-7cd134295404',
            'status' => 'active',
        ]);

        CentralWalletAccountLink::query()->create([
            'id' => '9',
            'site_code' => 'radiumbox.com',
            'local_user_id' => '499465',
            'desk_customer_id' => 'be49594b-289c-44d6-b9e4-6c8d3a810227',
            'central_wallet_id' => '5a3d0706-9f4b-4adc-b3d8-7cd134295404',
            'status' => 'active',
            'verification_method' => 'migration_cohort_anchor',
            'linked_at' => now(),
            'created_by' => 'test:refund360-migration-exec',
        ]);

        $user = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RB484',
            'serial_number' => 'SN-RB484',
            'product_name' => 'Test',
            'device_model' => 'Model',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        RefundRequest::query()->create([
            'id' => 360,
            'order_id' => $order->id,
            'reference_no' => 'REF-67363',
            'amount' => '849.00',
            'reason' => 'Refund 360 migration execution test',
            'status' => RefundStatus::Closed,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'refund_amount' => '849.00',
            'requested_by' => $user->id,
        ]);
    }
}
