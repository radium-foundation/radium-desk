<?php

namespace Tests\Unit\Backup;

use App\Services\Backup\BackupWatchdogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupWatchdogServiceTest extends TestCase
{
    private string $stagingRoot;

    private string $statusPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stagingRoot = storage_path('framework/testing/backup-watchdog-'.uniqid());
        File::makeDirectory($this->stagingRoot.'/runs', 0755, true);
        $this->statusPath = $this->stagingRoot.'/last-run-status.json';

        config([
            'backup.staging_root' => $this->stagingRoot,
            'backup.watchdog.enabled' => true,
            'backup.watchdog.status_path' => $this->statusPath,
            'backup.watchdog.stale_hours' => 26,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->stagingRoot);

        parent::tearDown();
    }

    public function test_returns_no_alerts_for_recent_successful_run(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-22T08:00:00Z'));

        $this->writeStatus([
            'version' => 1,
            'generated_at' => '2026-08-22T02:30:00Z',
            'outcome' => 'success',
            'exit_code' => 0,
            'duration_seconds' => 120,
            'lock_acquired' => true,
            'cloud_upload_enabled' => true,
            'backup_id' => '20260822T023001Z',
            'phase' => 'cloud_uploaded',
            'error_summary' => null,
        ]);

        $alerts = app(BackupWatchdogService::class)->collectAlerts();

        $this->assertSame([], $alerts);
    }

    public function test_alerts_on_local_failure(): void
    {
        $this->writeStatus([
            'version' => 1,
            'generated_at' => '2026-08-22T02:30:00Z',
            'outcome' => 'local_failure',
            'exit_code' => 1,
            'duration_seconds' => 15,
            'lock_acquired' => true,
            'cloud_upload_enabled' => true,
            'backup_id' => null,
            'phase' => null,
            'error_summary' => 'mysqldump failed',
        ]);

        $alerts = app(BackupWatchdogService::class)->collectAlerts();

        $this->assertCount(1, $alerts);
        $this->assertSame('backup:local_failure', $alerts[0]->key);
        $this->assertStringContainsString('Local encrypted backup staging failed.', $alerts[0]->message);
        $this->assertStringContainsString('mysqldump failed', $alerts[0]->message);
    }

    public function test_alerts_on_cloud_upload_failure(): void
    {
        $this->writeStatus([
            'version' => 1,
            'generated_at' => '2026-08-22T02:30:00Z',
            'outcome' => 'cloud_upload_failure',
            'exit_code' => 1,
            'duration_seconds' => 300,
            'lock_acquired' => true,
            'cloud_upload_enabled' => true,
            'backup_id' => '20260822T023001Z',
            'phase' => null,
            'error_summary' => 'rsync to remote staging directory failed',
        ]);

        $alerts = app(BackupWatchdogService::class)->collectAlerts();

        $this->assertCount(1, $alerts);
        $this->assertSame('backup:cloud_upload_failure', $alerts[0]->key);
        $this->assertStringContainsString('Cloud backup upload failed', $alerts[0]->message);
    }

    public function test_alerts_on_lock_overlap(): void
    {
        $this->writeStatus([
            'version' => 1,
            'generated_at' => '2026-08-22T02:30:00Z',
            'outcome' => 'lock_overlap',
            'exit_code' => 0,
            'duration_seconds' => 0,
            'lock_acquired' => false,
            'cloud_upload_enabled' => true,
            'backup_id' => null,
            'phase' => null,
            'error_summary' => 'Another backup run is still holding the schedule lock.',
        ]);

        $alerts = app(BackupWatchdogService::class)->collectAlerts();

        $this->assertCount(1, $alerts);
        $this->assertSame('backup:lock_overlap', $alerts[0]->key);
    }

    public function test_alerts_on_stale_backup(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24T10:00:00Z'));

        $this->writeStatus([
            'version' => 1,
            'generated_at' => '2026-08-22T02:30:00Z',
            'outcome' => 'success',
            'exit_code' => 0,
            'duration_seconds' => 120,
            'lock_acquired' => true,
            'cloud_upload_enabled' => true,
            'backup_id' => '20260822T023001Z',
            'phase' => 'cloud_uploaded',
            'error_summary' => null,
        ]);

        $alerts = app(BackupWatchdogService::class)->collectAlerts();

        $this->assertCount(1, $alerts);
        $this->assertSame('backup:stale', $alerts[0]->key);
        $this->assertStringContainsString('threshold 26 hour(s)', $alerts[0]->message);
    }

    public function test_uses_manifest_latest_when_status_is_failed_but_history_is_fresh(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-22T08:00:00Z'));

        $this->writeManifest('20260822T023001Z');
        $this->writeStatus([
            'version' => 1,
            'generated_at' => '2026-08-22T08:00:00Z',
            'outcome' => 'local_failure',
            'exit_code' => 1,
            'duration_seconds' => 10,
            'lock_acquired' => true,
            'cloud_upload_enabled' => true,
            'backup_id' => null,
            'phase' => null,
            'error_summary' => 'mysqldump failed',
        ]);

        $alerts = app(BackupWatchdogService::class)->collectAlerts();

        $keys = array_map(static fn ($alert) => $alert->key, $alerts);

        $this->assertContains('backup:local_failure', $keys);
        $this->assertNotContains('backup:stale', $keys);
    }

    public function test_ignores_status_when_watchdog_accessible_is_false(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-22T08:00:00Z'));
        $this->writeManifest('20260822T023001Z');

        $this->writeStatus([
            'version' => 1,
            'generated_at' => '2026-08-22T02:30:00Z',
            'outcome' => 'local_failure',
            'exit_code' => 1,
            'duration_seconds' => 10,
            'lock_acquired' => true,
            'cloud_upload_enabled' => true,
            'watchdog_accessible' => false,
            'backup_id' => null,
            'phase' => null,
            'error_summary' => 'mysqldump failed',
        ]);

        $alerts = app(BackupWatchdogService::class)->collectAlerts();
        $keys = array_map(static fn ($alert) => $alert->key, $alerts);

        $this->assertNotContains('backup:local_failure', $keys);
        $this->assertNotContains('backup:cloud_upload_failure', $keys);
        $this->assertNotContains('backup:lock_overlap', $keys);
    }

    public function test_disabled_watchdog_returns_no_alerts(): void
    {
        config(['backup.watchdog.enabled' => false]);

        $this->writeStatus([
            'version' => 1,
            'generated_at' => '2026-08-22T02:30:00Z',
            'outcome' => 'local_failure',
            'exit_code' => 1,
            'duration_seconds' => 10,
            'lock_acquired' => true,
            'cloud_upload_enabled' => true,
            'backup_id' => null,
            'phase' => null,
            'error_summary' => 'mysqldump failed',
        ]);

        $this->assertSame([], app(BackupWatchdogService::class)->collectAlerts());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeStatus(array $payload): void
    {
        File::put(
            $this->statusPath,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    private function writeManifest(string $backupId): void
    {
        $runDir = $this->stagingRoot.'/runs/'.$backupId;
        File::makeDirectory($runDir, 0755, true);

        File::put($runDir.'/manifest.json', json_encode([
            'backup_id' => $backupId,
            'created_at' => '2026-08-22T02:30:01Z',
            'phase' => 'cloud_uploaded',
            'application' => ['version' => '4.0.55', 'build' => '894b1c38'],
            'database' => ['name' => 'radium_desk'],
            'artifacts' => [
                [
                    'role' => 'database',
                    'filename' => 'database.sql.gz.gpg',
                    'size_bytes' => 100,
                    'sha256' => str_repeat('a', 64),
                    'encryption' => 'gpg-aes256-symmetric',
                ],
                [
                    'role' => 'secrets',
                    'filename' => 'secrets.tar.gz.gpg',
                    'size_bytes' => 50,
                    'sha256' => str_repeat('b', 64),
                    'encryption' => 'gpg-aes256-symmetric',
                ],
            ],
            'upload' => [
                'status' => 'completed',
                'uploaded_at' => '2026-08-22T02:31:00Z',
                'remote_host' => 'example-host',
                'remote_path' => '/remote/path/'.$backupId,
                'artifacts_verified' => true,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
