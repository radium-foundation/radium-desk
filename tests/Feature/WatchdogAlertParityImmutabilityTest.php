<?php

namespace Tests\Feature;

use App\Data\Operations\ProductionCriticalAlert;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\BonvoiceWebhookLog;
use App\Models\Order;
use App\Models\User;
use App\Services\Operations\ProductionWatchdogService;
use App\Services\Operations\WatchdogCriticalAlertGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WatchdogAlertParityImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private const UPTIME_KEY_PREFIX = 'watchdog:uptime:';

    private const RADIUMBOX_HEALTH_KEY = 'operations:radiumbox-health';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['localhost/*' => Http::response('OK', 200)]);
        config([
            'ira.watchdog.radiumbox_hygiene_enabled' => false,
            'ira.watchdog.dead_letter_hygiene_enabled' => false,
            'ira.watchdog.bonvoice_hygiene_enabled' => false,
            'ira.watchdog.actionable_hours' => 48,
            'ira.watchdog.stale_days' => 30,
            'infrastructure.queue_worker_mode' => 'dedicated_cron',
            'radiumbox.enabled' => true,
        ]);
        WatchdogCriticalAlertGate::clearDurableForTests();
    }

    public function test_collect_parity_alerts_matches_stream_specific_legacy_paths(): void
    {
        $this->createFailedOrder('RD900010', now()->subHours(2));
        $this->createFailedOrder('RD900011', now()->subDays(40));

        $watchdog = app(ProductionWatchdogService::class);

        config(['ira.watchdog.radiumbox_hygiene_enabled' => false]);
        $legacyViaCollect = $this->firstAffectedCount(
            $watchdog->collectParityAlerts(false),
            'radiumbox:sync_failures',
        );
        $this->assertSame(2, $legacyViaCollect);

        config(['ira.watchdog.radiumbox_hygiene_enabled' => true]);
        $hygieneViaCollect = $this->firstAffectedCount(
            $watchdog->collectParityAlerts(true),
            'radiumbox:sync_failures',
        );
        $this->assertSame(1, $hygieneViaCollect);
    }

    public function test_parity_command_does_not_mutate_cache_gate_or_queue(): void
    {
        Queue::fake();

        $this->createFailedOrder('RD900012', now()->subDays(35));

        $date = now()->toDateString();
        $uptimeKey = self::UPTIME_KEY_PREFIX.$date;
        $uptimeBefore = ['total' => 5, 'degraded' => 1, 'incidents' => 1, 'last_healthy' => true];
        Cache::put($uptimeKey, $uptimeBefore, now()->addDay());
        Cache::forget(self::RADIUMBOX_HEALTH_KEY);

        $gatePath = storage_path('framework/testing/watchdog-critical-fingerprints.json');
        File::put($gatePath, json_encode(['index' => ['queue:dead_letter'], 'states' => []], JSON_THROW_ON_ERROR));
        $gateMtimeBefore = filemtime($gatePath);
        $gateContentsBefore = (string) File::get($gatePath);

        $failedJobsBefore = (int) DB::table('failed_jobs')->count();
        $ordersBefore = (int) Order::query()->count();

        $this->artisan('watchdog:alert-parity')->assertSuccessful();

        $this->assertSame($uptimeBefore, Cache::get($uptimeKey));
        $this->assertNull(Cache::get(self::RADIUMBOX_HEALTH_KEY));
        $this->assertSame($gateContentsBefore, File::get($gatePath));
        $this->assertSame($gateMtimeBefore, filemtime($gatePath));
        $this->assertSame($failedJobsBefore, (int) DB::table('failed_jobs')->count());
        $this->assertSame($ordersBefore, (int) Order::query()->count());

        Queue::assertNothingPushed();
    }

    public function test_parity_command_leaves_warm_radiumbox_health_cache_unchanged(): void
    {
        Queue::fake();

        $warmPayload = ['failed_syncs' => 99, 'enabled' => true];
        Cache::put(self::RADIUMBOX_HEALTH_KEY, $warmPayload, now()->addMinute());

        $this->artisan('watchdog:alert-parity')->assertSuccessful();

        $this->assertSame($warmPayload, Cache::get(self::RADIUMBOX_HEALTH_KEY));
        Queue::assertNothingPushed();
    }

    public function test_parity_metrics_reconcile_with_hygiene_classifiers(): void
    {
        $order = $this->createFailedOrder('RD900013', now()->subHours(2));
        $order->update(['radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::NotSynced]);
        $this->insertEnrichmentFailedJob('uuid-parity-013', $order->id, now());

        BonvoiceWebhookLog::query()->create([
            'event_type' => '2:NO_CHANNEL',
            'payload' => ['Status' => 'NO_CHANNEL', 'callType' => '2', 'callID' => null],
            'processing_status' => BonvoiceWebhookLog::STATUS_FAILED,
            'processing_error' => 'BonVoice webhook payload is missing callID.',
            'received_at' => now(),
        ]);

        $watchdog = app(ProductionWatchdogService::class);
        $legacy = $watchdog->collectParityAlerts(false);
        $hygiene = $watchdog->collectParityAlerts(true);

        $this->artisan('watchdog:alert-parity')->assertSuccessful();

        $this->assertSame(1, $this->firstAffectedCount($legacy, 'queue:dead_letter'));
        $this->assertSame(1, $this->firstAffectedCount($hygiene, 'queue:dead_letter'));
        $this->assertSame(0, $this->firstAffectedCount($hygiene, 'bonvoice:webhook_failures'));
        $this->assertSame(1, $this->firstAffectedCount($legacy, 'bonvoice:webhook_failures'));
    }

    /**
     * @param  list<ProductionCriticalAlert>  $alerts
     */
    private function firstAffectedCount(array $alerts, string $key): int
    {
        foreach ($alerts as $alert) {
            if ($alert->key === $key) {
                return (int) $alert->affectedCount;
            }
        }

        return 0;
    }

    private function createFailedOrder(string $orderId, Carbon $lastSyncAt): Order
    {
        $actor = User::factory()->create();

        return Order::query()->create([
            'order_id' => $orderId,
            'customer_name' => 'Watchdog Parity Test',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Failed,
            'radiumbox_last_sync_at' => $lastSyncAt,
        ]);
    }

    private function insertEnrichmentFailedJob(string $uuid, int $orderId, Carbon $failedAt): void
    {
        $serialized = 'O:36:"App\\Jobs\\RadiumBoxOrderEnrichmentJob":1:{s:7:"orderId";i:'.$orderId.';}';

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'redis',
            'queue' => 'critical',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\RadiumBoxOrderEnrichmentJob',
                'data' => ['command' => $serialized],
            ], JSON_THROW_ON_ERROR),
            'exception' => 'MaxAttemptsExceededException',
            'failed_at' => $failedAt,
        ]);
    }
}
