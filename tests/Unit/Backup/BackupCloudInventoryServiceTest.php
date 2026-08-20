<?php

namespace Tests\Unit\Backup;

use App\Services\Backup\BackupCloudInventoryService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupCloudInventoryServiceTest extends TestCase
{
    private string $stagingRoot;

    private string $indexPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stagingRoot = storage_path('framework/testing/backup-cloud-inventory-'.uniqid());
        File::makeDirectory($this->stagingRoot, 0755, true);
        $this->indexPath = $this->stagingRoot.'/cloud-inventory.json';

        config([
            'backup.cloud_inventory_path' => $this->indexPath,
            'backup.cloud_inventory_limit' => 10,
            'app.timezone' => 'Asia/Kolkata',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->stagingRoot);

        parent::tearDown();
    }

    public function test_parses_valid_index_entries_in_ist(): void
    {
        $this->writeIndex([
            'version' => 1,
            'generated_at' => '2026-08-20T08:35:00Z',
            'entries' => [
                [
                    'backup_id' => '20260820T083001Z',
                    'timestamp_utc' => '2026-08-20T08:30:01Z',
                    'total_size_bytes' => 349254795,
                    'manifest_present' => true,
                    'upload_complete' => true,
                ],
            ],
        ]);

        $summary = app(BackupCloudInventoryService::class)->summary();

        $this->assertTrue($summary['index_accessible']);
        $this->assertCount(1, $summary['entries']);
        $this->assertSame('20260820T083001Z', $summary['entries'][0]['backup_id']);
        $this->assertSame('333.1 MB', $summary['entries'][0]['total_size_label']);
        $this->assertSame('Yes', $summary['entries'][0]['manifest_present_label']);
        $this->assertSame('Yes', $summary['entries'][0]['upload_complete_label']);
        $this->assertStringContainsString('IST', $summary['entries'][0]['timestamp_label']);
        $this->assertStringNotContainsString('remote_path', json_encode($summary));
        $this->assertStringNotContainsString('.gpg', json_encode($summary));
    }

    public function test_skips_malformed_entries_and_sorts_newest_first(): void
    {
        $this->writeIndex([
            'version' => 1,
            'generated_at' => '2026-08-20T08:35:00Z',
            'entries' => [
                [
                    'backup_id' => '20260818T185214Z',
                    'timestamp_utc' => '2026-08-18T18:52:14Z',
                    'total_size_bytes' => 100,
                    'manifest_present' => true,
                    'upload_complete' => true,
                ],
                [
                    'backup_id' => '20260820T083001Z',
                    'timestamp_utc' => '2026-08-20T08:30:01Z',
                    'total_size_bytes' => 300,
                    'manifest_present' => true,
                    'upload_complete' => true,
                ],
                [
                    'backup_id' => 'bad-id',
                    'timestamp_utc' => '2026-08-19T08:30:01Z',
                    'total_size_bytes' => 200,
                    'manifest_present' => true,
                    'upload_complete' => true,
                ],
                [
                    'backup_id' => '20260819T083001Z',
                    'timestamp_utc' => '2026-08-19T08:30:01Z',
                    'total_size_bytes' => 250,
                    'manifest_present' => true,
                    'upload_complete' => true,
                    'remote_path' => '/secret/path',
                ],
                [
                    'backup_id' => '20260819T203001Z',
                    'timestamp_utc' => '2026-08-19T20:30:01Z',
                    'total_size_bytes' => -1,
                    'manifest_present' => true,
                    'upload_complete' => true,
                ],
            ],
        ]);

        $summary = app(BackupCloudInventoryService::class)->summary();

        $this->assertCount(2, $summary['entries']);
        $this->assertSame('20260820T083001Z', $summary['entries'][0]['backup_id']);
        $this->assertSame('20260818T185214Z', $summary['entries'][1]['backup_id']);
    }

    public function test_returns_empty_entries_when_index_is_missing(): void
    {
        $summary = app(BackupCloudInventoryService::class)->summary();

        $this->assertFalse($summary['index_accessible']);
        $this->assertSame([], $summary['entries']);
        $this->assertNotEmpty($summary['index_unavailable_message']);
    }

    public function test_returns_parse_error_for_invalid_json(): void
    {
        File::put($this->indexPath, '{not-json');

        $summary = app(BackupCloudInventoryService::class)->summary();

        $this->assertTrue($summary['index_accessible']);
        $this->assertTrue($summary['index_parse_error']);
        $this->assertSame([], $summary['entries']);
        $this->assertNotEmpty($summary['index_parse_error_message']);
        $this->assertNull($summary['index_unavailable_message']);
    }

    public function test_valid_index_with_zero_entries_is_not_a_parse_error(): void
    {
        $this->writeIndex([
            'version' => 1,
            'generated_at' => '2026-08-20T08:35:00Z',
            'entries' => [],
        ]);

        $summary = app(BackupCloudInventoryService::class)->summary();

        $this->assertTrue($summary['index_accessible']);
        $this->assertFalse($summary['index_parse_error']);
        $this->assertSame([], $summary['entries']);
        $this->assertNull($summary['index_parse_error_message']);
    }

    public function test_history_limit_applies_to_cloud_entries(): void
    {
        config(['backup.cloud_inventory_limit' => 2]);

        $entries = [];

        foreach (['20260818T100000Z', '20260818T110000Z', '20260818T120000Z'] as $backupId) {
            $entries[] = [
                'backup_id' => $backupId,
                'timestamp_utc' => '2026-08-18T10:00:00Z',
                'total_size_bytes' => 100,
                'manifest_present' => true,
                'upload_complete' => true,
            ];
        }

        $this->writeIndex([
            'version' => 1,
            'generated_at' => '2026-08-20T08:35:00Z',
            'entries' => $entries,
        ]);

        $summary = app(BackupCloudInventoryService::class)->summary();

        $this->assertCount(2, $summary['entries']);
        $this->assertSame('20260818T120000Z', $summary['entries'][0]['backup_id']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeIndex(array $payload): void
    {
        File::put(
            $this->indexPath,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }
}
