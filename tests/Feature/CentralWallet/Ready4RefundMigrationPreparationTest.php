<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\Ready4FinancialMigrationManifestLoader;
use App\CentralWallet\Application\Ready4RefundMigrationDryRunService;
use App\CentralWallet\Application\Ready4RefundMigrationJournalImportService;
use App\CentralWallet\Application\Ready4RefundMigrationRehearseService;
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
use Tests\TestCase;

class Ready4RefundMigrationPreparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config([
            'central_wallet.ready4_financial_migration.manifest_path' => base_path(
                'tests/fixtures/cw-type1-ready4-financial-preflight-p30-10-12.json',
            ),
            'central_wallet.refund_migration.execution_enabled' => false,
        ]);
    }

    public function test_preflight_manifest_validates_exact_4_rows_and_amount(): void
    {
        $manifest = app(Ready4FinancialMigrationManifestLoader::class)->load();

        $this->assertSame(4, count($manifest['rows']));
        $this->assertSame('2344.00', $manifest['refund_amount']);
        $this->assertSame(Ready4FinancialMigrationManifestLoader::BATCH_ID, $manifest['batch_id']);
        $this->assertSame(3, $manifest['executable_count']);
    }

    public function test_manifest_loader_rejects_hash_mismatch(): void
    {
        $manifest = json_decode(
            file_get_contents(base_path('tests/fixtures/cw-type1-ready4-financial-preflight-p30-10-12.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $manifest['manifest_rows_sha256'] = 'deadbeef';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ready4_financial_migration_manifest_hash_mismatch');
        app(Ready4FinancialMigrationManifestLoader::class)->validate($manifest);
    }

    public function test_import_creates_3_prepared_journal_rows_without_ledger_writes(): void
    {
        $this->seedManifestIdentities();

        $result = app(Ready4RefundMigrationJournalImportService::class)->import();

        $this->assertSame(3, $result['imported']);
        $this->assertSame(3, $result['assigned']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(1, $result['blocked_manifest']);
        $this->assertDatabaseCount('central_wallet_refund_migrations', 3);
        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());

        $migration = CentralWalletRefundMigration::query()->where('refund_id', 268)->firstOrFail();
        $this->assertSame(RefundMigrationLane::Lane1SpokeCutover, $migration->lane);
        $this->assertSame(RefundMigrationStatus::Prepared, $migration->status);
        $this->assertSame('rdservice.in', $migration->source_application);
        $this->assertNotNull($migration->desk_customer_id);
        $this->assertNotNull($migration->cwid);
    }

    public function test_import_is_idempotent(): void
    {
        $this->seedManifestIdentities();

        $service = app(Ready4RefundMigrationJournalImportService::class);
        $first = $service->import();
        $second = $service->import();

        $this->assertSame(3, $first['imported']);
        $this->assertSame(0, $second['imported']);
        $this->assertSame(3, $second['skipped']);
        $this->assertDatabaseCount('central_wallet_refund_migrations', 3);
    }

    public function test_dry_run_passes_for_executable_journal_without_financial_writes(): void
    {
        $this->seedManifestIdentities();
        app(Ready4RefundMigrationJournalImportService::class)->import();

        $report = app(Ready4RefundMigrationDryRunService::class)->run();

        $this->assertTrue($report['dry_run']);
        $this->assertTrue($report['executable_batch_ready']);
        $this->assertFalse($report['cohort_batch_ready']);
        $this->assertSame(3, $report['ready_count']);
        $this->assertSame('1495.00', $report['journal_amount']);
        $this->assertSame(1, $report['blocked_manifest_count']);
        $this->assertSame(0, $report['duplicate_ledger_credit_count']);
        $this->assertFalse($report['financial_execution_performed']);
        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
    }

    public function test_executor_rehearsal_passes_for_executable_rows_without_financial_writes(): void
    {
        $this->seedManifestIdentities();
        app(Ready4RefundMigrationJournalImportService::class)->import();

        $report = app(Ready4RefundMigrationRehearseService::class)->run();

        $this->assertTrue($report['rehearsal']);
        $this->assertFalse($report['financial_writes']);
        $this->assertTrue($report['executable_rehearsal_pass']);
        $this->assertFalse($report['cohort_rehearsal_pass']);
        $this->assertTrue($report['executor_trace']['executor_resolvable']);
        $this->assertTrue($report['representative_row']['rollback_trace']['uses_ledger_reversal']);
        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
    }

    public function test_cohort_isolation_from_type1_batch(): void
    {
        $this->seedManifestIdentities();
        app(Ready4RefundMigrationJournalImportService::class)->import();

        $this->assertSame(
            3,
            CentralWalletRefundMigration::query()
                ->where('batch_id', Ready4FinancialMigrationManifestLoader::BATCH_ID)
                ->count(),
        );

        $this->assertSame(
            0,
            CentralWalletRefundMigration::query()
                ->where('batch_id', 'desk-refund-wallet-migration-type1-50-p30-10-06')
                ->count(),
        );
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
                'created_by' => 'test:ready4-refund-migration-prep',
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
                'reason' => 'Ready4 migration preparation test',
                'status' => RefundStatus::Closed,
                'approved_refund_method' => ApprovedRefundMethod::Wallet,
                'refund_amount' => (string) $row['refund_amount'],
                'requested_by' => $user->id,
            ]);
        }
    }
}
