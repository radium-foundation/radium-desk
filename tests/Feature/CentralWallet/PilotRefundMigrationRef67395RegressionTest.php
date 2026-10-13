<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\Contracts\WalletMigrationSpokeClient;
use App\CentralWallet\Application\PilotRefundMigrationJournalImportService;
use App\CentralWallet\Application\PilotRefundMigrationManifestLoader;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\RefundRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeWalletMigrationSpokeClient;
use Tests\TestCase;

class PilotRefundMigrationRef67395RegressionTest extends TestCase
{
    use RefreshDatabase;

    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->app->singleton(FakeWalletMigrationSpokeClient::class, fn (): FakeWalletMigrationSpokeClient => new FakeWalletMigrationSpokeClient);
        $this->app->singleton(
            WalletMigrationSpokeClient::class,
            fn (): FakeWalletMigrationSpokeClient => app(FakeWalletMigrationSpokeClient::class),
        );

        $this->manifestPath = base_path('tests/fixtures/cw-pilot-refund-ref-67395-manifest.json');

        config([
            'central_wallet.enabled' => true,
            'central_wallet.balance_migration.execution_enabled' => false,
            'central_wallet.refund_migration.execution_enabled' => false,
            'central_wallet.pilot_refund_migration.manifest_path' => $this->manifestPath,
            'central_wallet.pilot_refund_migration.allowed_refund_ids' => '390',
            'central_wallet.pilot_refund_migration.required_owner_approval_ref' => 'OWNER-PILOT-REF-67395-TEST',
        ]);
    }

    public function test_ref_67395_manifest_matches_production_regression_fingerprint(): void
    {
        $manifest = app(PilotRefundMigrationManifestLoader::class)->load($this->manifestPath);

        $this->assertSame([390], $manifest['allowed_refund_ids']);
        $this->assertSame('REF-67395', $manifest['rows'][0]['desk_refund_reference']);
        $this->assertSame('052076aa8f61071e68d46b204f6bdee9c560a98a6fe99841c77f45b6df1a5305', $manifest['manifest_rows_sha256']);
    }

    public function test_ref_67395_manifest_imports_journal_row_without_execution(): void
    {
        $this->seedRef67395RegressionData();

        $result = app(PilotRefundMigrationJournalImportService::class)->import($this->manifestPath);

        $this->assertSame(1, $result['imported'] ?? 0);
        $this->assertDatabaseHas('central_wallet_refund_migrations', [
            'refund_id' => 390,
            'refund_reference' => 'REF-67395',
        ]);
    }

    private function seedRef67395RegressionData(): void
    {
        $cwid = 'd1852026-d8c2-4af4-818c-d3151549f614';
        CentralWallet::query()->forceCreate([
            'id' => $cwid,
            'status' => 'active',
        ]);

        CentralCustomer::query()->create([
            'id' => 'c51247d0-bb8a-4924-8389-8503ba32f5bc',
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);

        $orderId = 62928;
        DB::table('orders')->insert([
            'id' => $orderId,
            'order_id' => 'RD17641',
            'customer_id' => 'c51247d0-bb8a-4924-8389-8503ba32f5bc',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create();

        RefundRequest::query()->forceCreate([
            'id' => 390,
            'order_id' => $orderId,
            'reference_no' => 'REF-67395',
            'amount' => '597.00',
            'refund_amount' => '597.00',
            'reason' => 'RD17641 regression fixture.',
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'status' => RefundStatus::Closed,
            'execution_transaction_id' => '2667',
            'requested_by' => $user->id,
            'communication_channels' => [],
        ]);
    }
}
