<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\NextSafeBatchDryRunService;
use App\CentralWallet\Application\NextSafeBatchJournalImportService;
use App\CentralWallet\Application\NextSafeBatchManifestLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NextSafeBatchPreparationTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../../fixtures/cw-next-safe-batch-empty-p30-10-24.json';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.refund_migration.execution_enabled' => false,
            'central_wallet.next_safe_batch.manifest_path' => self::FIXTURE,
        ]);
    }

    public function test_empty_manifest_validates_with_known_sha256(): void
    {
        $manifest = app(NextSafeBatchManifestLoader::class)->load();

        $this->assertSame(NextSafeBatchManifestLoader::BATCH_ID, $manifest['batch_id']);
        $this->assertSame(0, $manifest['refund_count']);
        $this->assertSame('0', $manifest['refund_amount']);
        $this->assertSame(NextSafeBatchManifestLoader::EMPTY_MANIFEST_ROWS_SHA256, $manifest['manifest_rows_sha256']);
    }

    public function test_class_b_empty_manifest_validates_with_p30_10_25_batch_id(): void
    {
        config([
            'central_wallet.next_safe_batch.manifest_path' => __DIR__.'/../../fixtures/cw-next-safe-batch-class-b-empty-p30-10-25.json',
        ]);

        $manifest = app(NextSafeBatchManifestLoader::class)->load();

        $this->assertSame(NextSafeBatchManifestLoader::BATCH_ID_CLASS_B_P30_10_25, $manifest['batch_id']);
        $this->assertSame(0, $manifest['refund_count']);
    }

    public function test_import_and_dry_run_succeed_for_empty_batch(): void
    {
        $import = app(NextSafeBatchJournalImportService::class)->import();
        $this->assertSame(0, $import['imported']);
        $this->assertSame(0, $import['skipped']);

        $report = app(NextSafeBatchDryRunService::class)->run();
        $this->assertTrue($report['dry_run']);
        $this->assertTrue($report['batch_empty']);
        $this->assertTrue($report['batch_ready']);
        $this->assertSame(0, $report['journal_count']);
        $this->assertFalse($report['execution_flag_enabled']);
    }
}
