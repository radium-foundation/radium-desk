<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\Refund360MigrationDryRunService;
use App\CentralWallet\Application\Refund360MigrationJournalImportService;
use App\CentralWallet\Application\Refund360MigrationManifestLoader;
use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Refund360MigrationPreparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.refund360_migration.manifest_path' => base_path(
                'tests/fixtures/cw-refund360-migration-p30-10-26.json',
            ),
            'central_wallet.refund_migration.execution_enabled' => false,
        ]);
    }

    public function test_manifest_validates_single_refund_360_row(): void
    {
        $manifest = app(Refund360MigrationManifestLoader::class)->load();

        $this->assertSame(1, count($manifest['rows']));
        $this->assertSame('849.00', $manifest['refund_amount']);
        $this->assertSame(Refund360MigrationManifestLoader::BATCH_ID, $manifest['batch_id']);
        $this->assertSame([360], Refund360MigrationManifestLoader::ALLOWED_REFUND_IDS);
    }

    public function test_import_creates_single_prepared_journal_row_for_radiumbox(): void
    {
        $this->seedRefund360Identity();

        $result = app(Refund360MigrationJournalImportService::class)->import();

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['skipped']);
        $this->assertDatabaseCount('central_wallet_refund_migrations', 1);
        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());

        $migration = CentralWalletRefundMigration::query()->where('refund_id', 360)->firstOrFail();
        $this->assertSame(RefundMigrationLane::Lane1SpokeCutover, $migration->lane);
        $this->assertSame(RefundMigrationStatus::Prepared, $migration->status);
        $this->assertSame('radiumbox.com', $migration->source_application);
        $this->assertSame(2567, $migration->source_wallet_id);
    }

    public function test_dry_run_reports_executable_batch_ready(): void
    {
        $this->seedRefund360Identity();
        app(Refund360MigrationJournalImportService::class)->import();

        $report = app(Refund360MigrationDryRunService::class)->run();

        $this->assertTrue($report['dry_run']);
        $this->assertTrue($report['executable_batch_ready']);
        $this->assertSame(1, $report['journal_count']);
        $this->assertSame('849.00', $report['journal_amount']);
        $this->assertFalse($report['financial_execution_performed']);
    }

    private function seedRefund360Identity(): void
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
            'created_by' => 'test:refund360-migration-prep',
        ]);
    }
}
