<?php

namespace Tests\Unit\Backup;

use App\Services\Backup\BackupStatusService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupStatusServiceTest extends TestCase
{
    private string $stagingRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stagingRoot = storage_path('framework/testing/backup-status-'.uniqid());
        File::makeDirectory($this->stagingRoot.'/runs', 0755, true);

        config([
            'backup.staging_root' => $this->stagingRoot,
            'backup.history_limit' => 10,
            'app.timezone' => 'Asia/Kolkata',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->stagingRoot);

        parent::tearDown();
    }

    public function test_parses_successful_cloud_uploaded_manifest_as_latest(): void
    {
        $this->writeManifest('20260818T185214Z', [
            'backup_id' => '20260818T185214Z',
            'created_at' => '2026-08-18T18:52:14Z',
            'phase' => 'cloud_uploaded',
            'application' => [
                'version' => '4.0.42',
                'build' => '800ed734',
            ],
            'artifacts' => [
                [
                    'role' => 'database',
                    'filename' => 'database.sql.gz.gpg',
                    'size_bytes' => 347192191,
                    'sha256' => '2630842d6646641b4d80b306c422705e03328626eb8609a66200fcecd017ddc7',
                ],
                [
                    'role' => 'secrets',
                    'filename' => 'secrets.tar.gz.gpg',
                    'size_bytes' => 6521,
                    'sha256' => '445866e9a1b03dc7a1f81ff8a318f11f2ed934d4dbb884accb7e0317872b995b',
                ],
            ],
            'upload' => [
                'status' => 'completed',
                'uploaded_at' => '2026-08-18T18:53:14Z',
                'remote_host' => '187.127.183.72',
                'remote_path' => '/home/u215544208/backups/radium-desk/2026/08/18/20260818T185214Z',
            ],
        ]);

        $summary = app(BackupStatusService::class)->summary();

        $this->assertTrue($summary['staging_accessible']);
        $this->assertNotNull($summary['latest']);
        $this->assertSame('20260818T185214Z', $summary['latest']['backup_id']);
        $this->assertSame('4.0.42', $summary['latest']['application_version']);
        $this->assertSame('800ed734', $summary['latest']['application_build']);
        $this->assertSame('completed', $summary['latest']['cloud_upload_status']);
        $this->assertSame('Uploaded to cloud', $summary['latest']['cloud_upload_status_label']);
        $this->assertSame('ok', $summary['latest']['integrity_status']);
        $this->assertSame(347192191, $summary['latest']['database_size_bytes']);
        $this->assertStringContainsString('MB', $summary['latest']['database_size_label']);
        $this->assertArrayNotHasKey('remote_path', $summary['latest']);
        $this->assertArrayNotHasKey('remote_host', $summary['latest']);
    }

    public function test_selects_newest_successful_backup_as_latest(): void
    {
        $this->writeManifest('20260818T184334Z', $this->validManifest('20260818T184334Z', 'cloud_uploaded'));
        $this->writeManifest('20260818T185214Z', $this->validManifest('20260818T185214Z', 'cloud_uploaded'));

        $summary = app(BackupStatusService::class)->summary();

        $this->assertSame('20260818T185214Z', $summary['latest']['backup_id']);
    }

    public function test_malformed_manifest_is_represented_safely(): void
    {
        $runDir = $this->stagingRoot.'/runs/20260818T120000Z';
        File::makeDirectory($runDir, 0755, true);
        File::put($runDir.'/manifest.json', '{not-json');

        $summary = app(BackupStatusService::class)->summary();

        $this->assertNull($summary['latest']);
        $this->assertCount(1, $summary['history']);
        $this->assertSame('malformed', $summary['history'][0]['integrity_status']);
        $this->assertSame('Malformed', $summary['history'][0]['status_label']);
    }

    public function test_incomplete_manifest_without_artifacts_is_not_latest(): void
    {
        $this->writeManifest('20260818T120000Z', [
            'backup_id' => '20260818T120000Z',
            'created_at' => '2026-08-18T12:00:00Z',
            'phase' => 'local_staging',
            'application' => [],
            'artifacts' => [],
        ]);

        $summary = app(BackupStatusService::class)->summary();

        $this->assertNull($summary['latest']);
        $this->assertSame('incomplete', $summary['history'][0]['integrity_status']);
        $this->assertSame('not_uploaded', $summary['history'][0]['cloud_upload_status']);
    }

    public function test_page_does_not_fail_when_staging_root_is_unreadable(): void
    {
        config(['backup.staging_root' => '/root/not-readable-backup-staging']);

        $summary = app(BackupStatusService::class)->summary();

        $this->assertFalse($summary['staging_accessible']);
        $this->assertNull($summary['latest']);
        $this->assertSame([], $summary['history']);
        $this->assertNotEmpty($summary['staging_unavailable_message']);
    }

    public function test_history_is_limited(): void
    {
        config(['backup.history_limit' => 2]);

        foreach (['20260818T100000Z', '20260818T110000Z', '20260818T120000Z'] as $backupId) {
            $this->writeManifest($backupId, $this->validManifest($backupId, 'local_staging'));
        }

        $summary = app(BackupStatusService::class)->summary();

        $this->assertCount(2, $summary['history']);
        $this->assertSame('20260818T120000Z', $summary['history'][0]['backup_id']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeManifest(string $backupId, array $payload): void
    {
        $runDir = $this->stagingRoot.'/runs/'.$backupId;
        File::makeDirectory($runDir, 0755, true);
        File::put(
            $runDir.'/manifest.json',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validManifest(string $backupId, string $phase): array
    {
        return [
            'backup_id' => $backupId,
            'created_at' => '2026-08-18T18:52:14Z',
            'phase' => $phase,
            'application' => [
                'version' => '4.0.42',
                'build' => '800ed734',
            ],
            'artifacts' => [
                [
                    'role' => 'database',
                    'filename' => 'database.sql.gz.gpg',
                    'size_bytes' => 1024,
                    'sha256' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                ],
                [
                    'role' => 'secrets',
                    'filename' => 'secrets.tar.gz.gpg',
                    'size_bytes' => 512,
                    'sha256' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                ],
            ],
            'upload' => $phase === 'cloud_uploaded'
                ? ['status' => 'completed']
                : null,
        ];
    }
}
