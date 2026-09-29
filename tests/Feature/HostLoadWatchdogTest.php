<?php

namespace Tests\Feature;

use App\Enums\IraNotificationStatus;
use App\Enums\IraNotificationType;
use App\Models\IraNotification;
use App\Models\User;
use App\Services\Operations\HostLoad\CpuProcessSampleReader;
use App\Services\Operations\HostLoad\HostLoadIncidentStore;
use App\Services\Operations\HostLoad\HostLoadProbe;
use App\Services\Operations\HostLoad\HostLoadProcessClassifier;
use App\Services\Operations\HostLoad\HostLoadSampleTracker;
use App\Services\Platform\Alerts\Contributors\HostLoadAlertContributor;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HostLoadWatchdogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'ira.communication.cooldown_minutes' => 60,
            'host_load_watchdog.enabled' => true,
            'host_load_watchdog.host_name' => 'test-host',
            'host_load_watchdog.project_name' => 'RadiumDesk',
            'host_load_watchdog.thresholds.elevated_load1' => 2.0,
            'host_load_watchdog.thresholds.elevated_consecutive_samples' => 3,
            'host_load_watchdog.thresholds.critical_load1' => 4.0,
            'host_load_watchdog.thresholds.critical_consecutive_samples' => 2,
            'host_load_watchdog.thresholds.normal_load1' => 1.5,
        ]);

        $this->enableTelegramNotifications();
        HostLoadIncidentStore::clearForTests();
        app(HostLoadSampleTracker::class)->clear();
    }

    protected function tearDown(): void
    {
        HostLoadIncidentStore::clearForTests();
        app(HostLoadSampleTracker::class)->clear();
        Cache::flush();

        parent::tearDown();
    }

    public function test_feature_disabled_exits_without_monitoring(): void
    {
        config(['host_load_watchdog.enabled' => false]);

        $this->mock(HostLoadProbe::class, function ($mock): void {
            $mock->shouldReceive('loadAverages')->never();
        });

        $this->artisan('host:monitor-load')
            ->expectsOutput('Host load watchdog is disabled.')
            ->assertSuccessful();

        $this->assertSame([], app(HostLoadIncidentStore::class)->allIncidents());
    }

    public function test_normal_load_does_not_create_incident(): void
    {
        $this->mockProbeSequence([1.0, 1.1, 1.2]);

        $this->runMonitorThreeTimes();

        $this->assertSame([], app(HostLoadIncidentStore::class)->openIncidents());
    }

    public function test_single_spike_does_not_create_incident(): void
    {
        $this->mockProbeSequence([1.0, 3.0, 1.0]);

        $this->runMonitorThreeTimes();

        $this->assertSame([], app(HostLoadIncidentStore::class)->openIncidents());
    }

    public function test_sustained_elevated_load_creates_incident_and_alerts(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 901],
            ], 200),
        ]);

        $this->createOwnerWithTelegram('911111111');
        $this->mockProbeSequence([2.5, 2.6, 2.7]);

        $this->runMonitorThreeTimes();

        $open = app(HostLoadIncidentStore::class)->openIncidents();
        $this->assertCount(1, $open);
        $this->assertSame('elevated', $open[0]->severity);
        $this->assertSame('ALERT_ONLY', $open[0]->action);
        $this->assertSame('host_load:elevated', $open[0]->alertKey);

        $this->assertDatabaseHas('ira_notifications', [
            'notification_type' => IraNotificationType::CriticalSystemAlert->value,
            'status' => IraNotificationStatus::Sent->value,
        ]);
    }

    public function test_sustained_critical_load_creates_critical_incident(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 902],
            ], 200),
        ]);

        $this->createOwnerWithTelegram('922222222');
        $this->mockProbeSequence([4.5, 4.6]);

        $this->artisan('host:monitor-load')->assertSuccessful();
        $this->artisan('host:monitor-load')->assertSuccessful();

        $open = app(HostLoadIncidentStore::class)->openIncidents();
        $this->assertCount(1, $open);
        $this->assertSame('critical', $open[0]->severity);
        $this->assertSame('host_load:critical', $open[0]->alertKey);
    }

    public function test_fingerprint_deduplication_suppresses_repeat_alerts(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 903],
            ], 200),
        ]);

        $this->createOwnerWithTelegram('933333333');
        $this->mockProbeSequence([2.5, 2.6, 2.7, 2.8, 2.9]);

        $this->runMonitorThreeTimes();
        $sentAfterFirst = IraNotification::query()
            ->where('status', IraNotificationStatus::Sent->value)
            ->count();

        $this->artisan('host:monitor-load')->assertSuccessful();
        $this->artisan('host:monitor-load')->assertSuccessful();

        $sentAfterRepeat = IraNotification::query()
            ->where('status', IraNotificationStatus::Sent->value)
            ->count();

        $this->assertSame($sentAfterFirst, $sentAfterRepeat);
        $this->assertSame('suppressed', app(HostLoadIncidentStore::class)->openIncidents()[0]->notification['telegram']);
    }

    public function test_unknown_process_is_classified_alert_only(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 904],
            ], 200),
        ]);

        $this->createOwnerWithTelegram('944444444');
        $this->mockProbeSequence([2.5, 2.6, 2.7]);
        $this->mockSamplerTopProcess([
            'cpu' => 88.0,
            'pid' => 4242,
            'command' => '/usr/bin/mystery-worker --run',
        ]);

        $this->runMonitorThreeTimes();

        $incident = app(HostLoadIncidentStore::class)->openIncidents()[0];
        $this->assertSame(HostLoadProcessClassifier::CLASSIFICATION_UNKNOWN, $incident->classification);
        $this->assertSame('ALERT_ONLY', $incident->action);
    }

    public function test_telegram_failure_persists_incident_without_crashing(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => false], 500),
        ]);

        $this->createOwnerWithTelegram('955555555');
        $this->mockProbeSequence([2.5, 2.6, 2.7]);

        $this->runMonitorThreeTimes();

        $incident = app(HostLoadIncidentStore::class)->openIncidents()[0];
        $this->assertSame('failed', $incident->notification['telegram']);
        $this->assertNotNull($incident->notification['error']);
    }

    public function test_recovery_resolves_open_incidents(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 905],
            ], 200),
        ]);

        $this->createOwnerWithTelegram('966666666');
        $this->mockProbeSequence([2.5, 2.6, 2.7, 1.0]);

        $this->runMonitorThreeTimes();
        $this->assertCount(1, app(HostLoadIncidentStore::class)->openIncidents());

        $this->artisan('host:monitor-load')->assertSuccessful();

        $this->assertSame([], app(HostLoadIncidentStore::class)->openIncidents());
        $resolved = array_values(array_filter(
            app(HostLoadIncidentStore::class)->allIncidents(),
            fn ($incident) => $incident->status === 'resolved',
        ));
        $this->assertCount(1, $resolved);
    }

    public function test_malformed_sampler_data_is_handled_safely(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 906],
            ], 200),
        ]);

        $directory = storage_path('framework/testing/cpu-samples');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.now('Asia/Kolkata')->format('Y-m-d').'.tsv';
        File::put($path, "broken\tline\n");

        config(['host_load_watchdog.sampler.directory' => $directory]);

        $this->createOwnerWithTelegram('977777777');
        $this->mockProbeSequence([2.5, 2.6, 2.7]);

        $this->runMonitorThreeTimes();

        $incident = app(HostLoadIncidentStore::class)->openIncidents()[0];
        $this->assertSame(HostLoadProcessClassifier::CLASSIFICATION_UNKNOWN, $incident->classification);
        $this->assertNull($incident->topProcess);

        File::deleteDirectory($directory);
    }

    public function test_command_returns_failure_when_monitoring_fails(): void
    {
        $this->mock(HostLoadProbe::class, function ($mock): void {
            $mock->shouldReceive('loadAverages')->andThrow(new \RuntimeException('probe failed'));
        });

        $this->artisan('host:monitor-load')->assertFailed();
    }

    public function test_platform_alert_contributor_reads_open_incidents_only(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 907],
            ], 200),
        ]);

        config(['host_load_watchdog.enabled' => false]);
        $this->assertSame([], app(HostLoadAlertContributor::class)->alerts());

        config(['host_load_watchdog.enabled' => true]);
        $this->createOwnerWithTelegram('988888888');
        $this->mockProbeSequence([2.5, 2.6, 2.7]);
        $this->runMonitorThreeTimes();

        $alerts = app(HostLoadAlertContributor::class)->alerts();
        $this->assertCount(1, $alerts);
        $this->assertSame('host_load', $alerts[0]->source);
    }

    public function test_incident_store_persists_atomic_json_payload(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 908],
            ], 200),
        ]);

        $this->createOwnerWithTelegram('999999999');
        $this->mockProbeSequence([2.5, 2.6, 2.7]);
        $this->runMonitorThreeTimes();

        $path = storage_path('framework/testing/host-load-incidents.json');
        $this->assertFileExists($path);

        $decoded = json_decode((string) File::get($path), true);
        $this->assertIsArray($decoded['incidents'] ?? null);
        $this->assertStringStartsWith('HL-', (string) $decoded['incidents'][0]['id']);
        $this->assertSame('ALERT_ONLY', $decoded['incidents'][0]['action']);
    }

    public function test_sample_reader_parses_latest_tsv_line(): void
    {
        $directory = storage_path('framework/testing/cpu-samples-parse');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.now('Asia/Kolkata')->format('Y-m-d').'.tsv';
        File::put($path, implode("\n", [
            'ts_ist	ts_utc	load1	load5	load15	top5	lve_usage',
            "2026-09-29T10:00:00+05:30\t2026-09-29T04:30:00Z\t1.2\t1.1\t1.0\t88.0,1234,/usr/bin/mystery-worker\t",
        ]));

        config(['host_load_watchdog.sampler.directory' => $directory]);

        $sample = app(CpuProcessSampleReader::class)->latestSample();
        $this->assertNotNull($sample);
        $this->assertSame(1.2, $sample['load1']);
        $this->assertSame(1234, $sample['top_process']['pid']);

        File::deleteDirectory($directory);
    }

    /**
     * @param  list<float>  $load1Values
     */
    private function mockProbeSequence(array $load1Values): void
    {
        $responses = array_map(
            fn (float $load1): array => [
                'load1' => $load1,
                'load5' => $load1,
                'load15' => $load1,
            ],
            $load1Values,
        );

        $this->mock(HostLoadProbe::class, function ($mock) use ($responses): void {
            $mock->shouldReceive('loadAverages')->andReturn(...$responses);
        });
    }

    /**
     * @param  array{cpu: float, pid: int, command: string}  $process
     */
    private function mockSamplerTopProcess(array $process): void
    {
        $this->mock(CpuProcessSampleReader::class, function ($mock) use ($process): void {
            $mock->shouldReceive('latestSample')->andReturn([
                'load1' => 2.7,
                'load5' => 2.5,
                'load15' => 2.0,
                'top_process' => $process,
            ]);
        });
    }

    private function runMonitorThreeTimes(): void
    {
        $this->artisan('host:monitor-load')->assertSuccessful();
        $this->artisan('host:monitor-load')->assertSuccessful();
        $this->artisan('host:monitor-load')->assertSuccessful();
    }

    private function createOwnerWithTelegram(string $chatId): User
    {
        $owner = User::factory()->create([
            'telegram_chat_id' => $chatId,
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $owner->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $owner;
    }
}
