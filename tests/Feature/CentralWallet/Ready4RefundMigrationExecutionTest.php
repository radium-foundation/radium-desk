<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\BalanceMigrationCutoverService;
use App\CentralWallet\Application\Contracts\WalletMigrationSpokeClient;
use App\CentralWallet\Application\Ready4FinancialMigrationManifestLoader;
use App\CentralWallet\Application\RefundMigrationLane1Executor;
use App\CentralWallet\Application\Ready4RefundMigrationJournalImportService;
use App\CentralWallet\Application\Ready4RefundMigrationOrchestrator;
use App\CentralWallet\Domain\Enums\RefundMigrationLane;
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

class Ready4RefundMigrationExecutionTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_REF = 'R-CW-T1-READY3-FIN-MIG-20261001-001';

    private FakeWalletMigrationSpokeClient $spoke;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->spoke = new FakeWalletMigrationSpokeClient;
        $this->app->instance(WalletMigrationSpokeClient::class, $this->spoke);
        $this->app->forgetInstance(RefundMigrationLane1Executor::class);
        $this->app->forgetInstance(BalanceMigrationCutoverService::class);
        $this->app->forgetInstance(Ready4RefundMigrationOrchestrator::class);

        config([
            'central_wallet.enabled' => true,
            'central_wallet.ready4_financial_migration.manifest_path' => base_path(
                'tests/fixtures/cw-type1-ready4-financial-preflight-p30-10-12.json',
            ),
            'central_wallet.balance_migration.execution_enabled' => true,
            'central_wallet.refund_migration.execution_enabled' => true,
        ]);
    }

    public function test_orchestrator_rejects_refund_360(): void
    {
        $migration = CentralWalletRefundMigration::query()->create([
            'id' => '00000000-0000-4000-8000-000000000360',
            'batch_id' => Ready4FinancialMigrationManifestLoader::BATCH_ID,
            'refund_id' => 360,
            'refund_reference' => 'REF-67363',
            'amount' => '849.00',
            'source_type' => 'spoke_wallet',
            'source_application' => 'radiumbox.com',
            'source_wallet_id' => 2567,
            'source_reference' => 'desk-refund-migration:refund_requests:360',
            'lane' => RefundMigrationLane::Lane1SpokeCutover,
            'status' => RefundMigrationStatus::Prepared,
            'idempotency_key' => 'desk-refund-migration:refund_requests:360',
            'prepared_at' => now(),
            'metadata' => [],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ready4_refund_id_not_allowed:360');
        app(Ready4RefundMigrationOrchestrator::class)->executeSingle($migration, self::OWNER_REF);
    }

    public function test_orchestrator_rejects_type1_batch_row(): void
    {
        $migration = CentralWalletRefundMigration::query()->create([
            'id' => '00000000-0000-4000-8000-000000000202',
            'batch_id' => 'desk-refund-wallet-migration-type1-50-p30-10-06',
            'refund_id' => 268,
            'refund_reference' => 'REF-2026-000268',
            'amount' => '499.00',
            'source_type' => 'spoke_wallet',
            'source_application' => 'rdservice.in',
            'source_wallet_id' => 2542,
            'source_reference' => 'desk-refund-migration:refund_requests:268',
            'lane' => RefundMigrationLane::Lane1SpokeCutover,
            'status' => RefundMigrationStatus::Prepared,
            'idempotency_key' => 'desk-refund-migration:refund_requests:268',
            'prepared_at' => now(),
            'metadata' => [],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not_ready4_migration_row');
        app(Ready4RefundMigrationOrchestrator::class)->executeSingle($migration, self::OWNER_REF);
    }

    public function test_execute_three_prepared_rows_totals_1495_and_is_idempotent(): void
    {
        $this->seedManifestIdentities();
        app(Ready4RefundMigrationJournalImportService::class)->import();

        $orchestrator = app(Ready4RefundMigrationOrchestrator::class);
        $total = '0.00';

        foreach (Ready4RefundMigrationOrchestrator::EXECUTABLE_REFUND_IDS as $refundId) {
            $migration = CentralWalletRefundMigration::query()->where('refund_id', $refundId)->firstOrFail();
            $result = $orchestrator->executeSingle($migration, self::OWNER_REF);
            $this->assertSame(RefundMigrationStatus::Reconciled->value, $result['status']);
            $total = bcadd($total, (string) $migration->amount, 2);

            $replay = $orchestrator->executeSingle($migration->fresh(), self::OWNER_REF);
            $this->assertTrue($replay['idempotent_replay'] ?? false);
        }

        $this->assertSame('1495.00', $total);
        $this->assertSame(3, CentralWalletLedgerEntry::query()->where('entry_type', 'credit')->count());
        $this->assertSame(3, CentralWalletRefundMigration::query()->where('status', RefundMigrationStatus::Reconciled)->count());
        $this->assertCount(3, $this->spoke->retireCalls);
    }

    private function seedManifestIdentities(): void
    {
        $manifest = json_decode(
            file_get_contents(base_path('tests/fixtures/cw-type1-ready4-financial-preflight-p30-10-12.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $user = User::factory()->create();

        foreach ($manifest['rows'] as $row) {
            if (($row['status'] ?? '') !== 'prepared') {
                continue;
            }

            CentralWallet::query()->forceCreate([
                'id' => (string) $row['cwid'],
                'status' => 'active',
            ]);

            CentralCustomer::query()->create([
                'id' => (string) $row['desk_customer_id'],
                'central_wallet_id' => (string) $row['cwid'],
                'status' => 'active',
            ]);

            CentralWalletAccountLink::query()->create([
                'central_wallet_id' => (string) $row['cwid'],
                'desk_customer_id' => (string) $row['desk_customer_id'],
                'site_code' => (string) $row['site'],
                'local_user_id' => (string) $row['local_user_id'],
                'status' => 'active',
                'verification_method' => 'migration_cohort_anchor',
                'linked_at' => now(),
                'created_by' => 'test:ready4-refund-migration-exec',
            ]);

            $order = Order::query()->create([
                'order_id' => (string) $row['order_number'],
                'serial_number' => 'SN-'.$row['order_number'],
                'product_name' => 'Test',
                'device_model' => 'Model',
                'status' => 'active',
                'created_by' => $user->id,
            ]);

            RefundRequest::query()->create([
                'id' => (int) $row['refund_id'],
                'order_id' => $order->id,
                'reference_no' => (string) $row['desk_refund_reference'],
                'amount' => (string) $row['refund_amount'],
                'reason' => 'Ready4 migration execution test',
                'status' => RefundStatus::Closed,
                'approved_refund_method' => ApprovedRefundMethod::Wallet,
                'refund_amount' => (string) $row['refund_amount'],
                'requested_by' => $user->id,
            ]);
        }
    }
}
