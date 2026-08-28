<?php

namespace Tests\Feature\Backup;

use App\Enums\IraNotificationStatus;
use App\Enums\IraNotificationType;
use App\Models\IraNotification;
use App\Models\User;
use App\Services\Operations\ProductionWatchdogService;
use App\Services\Operations\WatchdogCriticalAlertGate;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackupWatchdogTest extends TestCase
{
    use RefreshDatabase;

    private string $stagingRoot;

    private string $statusPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        $this->stagingRoot = storage_path('framework/testing/backup-watchdog-feature-'.uniqid());
        File::makeDirectory($this->stagingRoot, 0755, true);
        $this->statusPath = $this->stagingRoot.'/last-run-status.json';

        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'backup.staging_root' => $this->stagingRoot,
            'backup.watchdog.enabled' => true,
            'backup.watchdog.status_path' => $this->statusPath,
            'backup.watchdog.stale_hours' => 26,
            'app.url' => 'http://localhost',
        ]);

        $this->enableTelegramNotifications();
        WatchdogCriticalAlertGate::clearDurableForTests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->stagingRoot);
        Cache::flush();

        parent::tearDown();
    }

    public function test_watchdog_sends_backup_local_failure_alert(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 501],
            ], 200),
            'localhost/*' => Http::response('OK', 200),
        ]);

        $this->createOwnerWithTelegram('911111111');
        $this->writeStatus([
            'outcome' => 'local_failure',
            'error_summary' => 'mysqldump failed',
            'generated_at' => now()->toIso8601String(),
        ]);

        $this->artisan('watchdog:send-critical-alerts')->assertSuccessful();

        $this->assertDatabaseHas('ira_notifications', [
            'notification_type' => IraNotificationType::CriticalSystemAlert->value,
            'status' => IraNotificationStatus::Sent->value,
        ]);

        $this->assertStringContainsString(
            'Local encrypted backup staging failed.',
            (string) IraNotification::query()->latest('id')->value('message'),
        );
    }

    public function test_backup_alert_fingerprint_suppresses_unchanged_repeats(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 502],
            ], 200),
            'localhost/*' => Http::response('OK', 200),
        ]);

        $this->createOwnerWithTelegram('922222222');
        $this->writeStatus([
            'outcome' => 'cloud_upload_failure',
            'backup_id' => '20260822T023001Z',
            'error_summary' => 'rsync to remote staging directory failed',
            'generated_at' => now()->toIso8601String(),
        ]);

        $this->artisan('watchdog:send-critical-alerts')->assertSuccessful();
        $sentAfterFirst = $this->sentBackupAlertCount();

        $this->artisan('watchdog:send-critical-alerts')->assertSuccessful();

        $this->assertSame($sentAfterFirst, $this->sentBackupAlertCount());
        $this->assertGreaterThanOrEqual(1, $sentAfterFirst);
    }

    public function test_successful_backup_status_resolves_backup_failure_alert(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 503],
            ], 200),
            'localhost/*' => Http::response('OK', 200),
        ]);

        $this->createOwnerWithTelegram('933333333');
        $this->writeStatus([
            'outcome' => 'local_failure',
            'error_summary' => 'mysqldump failed',
            'generated_at' => now()->toIso8601String(),
        ]);

        $this->artisan('watchdog:send-critical-alerts')->assertSuccessful();
        $this->assertSame(1, $this->sentBackupAlertCount());

        Carbon::setTestNow(now()->addHour());
        $this->writeStatus([
            'outcome' => 'success',
            'backup_id' => '20260822T083001Z',
            'phase' => 'cloud_uploaded',
            'error_summary' => null,
            'generated_at' => now()->toIso8601String(),
        ]);

        $backupAlerts = array_values(array_filter(
            app(ProductionWatchdogService::class)->collectCriticalAlerts(),
            fn ($alert) => str_starts_with($alert->key, 'backup:'),
        ));
        $this->assertSame([], $backupAlerts);

        $this->artisan('watchdog:send-critical-alerts')->assertSuccessful();
        $this->assertSame(1, $this->sentBackupAlertCount());

        $this->writeStatus([
            'outcome' => 'cloud_upload_failure',
            'backup_id' => '20260822T143001Z',
            'error_summary' => 'rsync upload-complete marker failed',
            'generated_at' => now()->toIso8601String(),
        ]);

        $this->artisan('watchdog:send-critical-alerts')->assertSuccessful();
        $this->assertSame(2, $this->sentBackupAlertCount());
    }

    public function test_stale_backup_alert_is_emitted_and_cleared_after_fresh_success(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 504],
            ], 200),
            'localhost/*' => Http::response('OK', 200),
        ]);

        $this->createOwnerWithTelegram('944444444');
        Carbon::setTestNow(Carbon::parse('2026-08-24T10:00:00Z'));

        $this->writeStatus([
            'outcome' => 'success',
            'backup_id' => '20260822T023001Z',
            'phase' => 'cloud_uploaded',
            'error_summary' => null,
            'generated_at' => '2026-08-22T02:30:00Z',
        ]);

        $this->artisan('watchdog:send-critical-alerts')->assertSuccessful();
        $this->assertGreaterThanOrEqual(1, $this->sentStaleBackupAlertCount());

        $this->writeStatus([
            'outcome' => 'success',
            'backup_id' => '20260824T023001Z',
            'phase' => 'cloud_uploaded',
            'error_summary' => null,
            'generated_at' => '2026-08-24T02:30:00Z',
        ]);

        $staleAlerts = array_values(array_filter(
            app(ProductionWatchdogService::class)->collectCriticalAlerts(),
            fn ($alert) => $alert->key === 'backup:stale',
        ));
        $this->assertSame([], $staleAlerts);
    }

    private function sentBackupAlertCount(): int
    {
        return IraNotification::query()
            ->where('notification_type', IraNotificationType::CriticalSystemAlert->value)
            ->where('status', IraNotificationStatus::Sent->value)
            ->where('message', 'like', '%backup%')
            ->count();
    }

    private function sentStaleBackupAlertCount(): int
    {
        return IraNotification::query()
            ->where('notification_type', IraNotificationType::CriticalSystemAlert->value)
            ->where('status', IraNotificationStatus::Sent->value)
            ->where('message', 'like', '%Latest successful backup is%')
            ->count();
    }

  /**
   * @param  array<string, mixed>  $overrides
   */
    private function writeStatus(array $overrides): void
    {
        File::put($this->statusPath, json_encode([
            'version' => 1,
            'generated_at' => $overrides['generated_at'] ?? now()->toIso8601String(),
            'outcome' => $overrides['outcome'] ?? 'local_failure',
            'exit_code' => $overrides['exit_code'] ?? 1,
            'duration_seconds' => $overrides['duration_seconds'] ?? 10,
            'lock_acquired' => $overrides['lock_acquired'] ?? true,
            'cloud_upload_enabled' => $overrides['cloud_upload_enabled'] ?? true,
            'backup_id' => $overrides['backup_id'] ?? null,
            'phase' => $overrides['phase'] ?? null,
            'error_summary' => $overrides['error_summary'] ?? null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function createOwnerWithTelegram(string $chatId, string $name = 'Owner User'): User
    {
        $owner = User::factory()->create([
            'name' => $name,
            'telegram_chat_id' => $chatId,
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $owner->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $owner;
    }
}
