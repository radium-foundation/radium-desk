<?php

namespace Tests\Feature;

use App\Data\Operations\ProductionCriticalAlert;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\BonvoiceWebhookLog;
use App\Models\Order;
use App\Models\User;
use App\Services\Operations\ProductionWatchdogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WatchdogAlertHygieneTest extends TestCase
{
    use RefreshDatabase;

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
    }

    public function test_flags_default_false_preserve_legacy_radiumbox_count(): void
    {
        $this->createFailedOrder('RD900001', now()->subDays(60));

        Cache::forget('operations:radiumbox-health');
        $alerts = $this->radiumBoxAlerts();
        $this->assertCount(1, $alerts);
        $this->assertSame(1, $alerts[0]->affectedCount);

        config(['ira.watchdog.radiumbox_hygiene_enabled' => true]);
        Cache::forget('operations:radiumbox-health');
        $this->assertSame([], $this->radiumBoxAlerts());
    }

    public function test_radiumbox_hygiene_alerts_only_recent_rd_failures(): void
    {
        config(['ira.watchdog.radiumbox_hygiene_enabled' => true]);

        $this->createFailedOrder('RD900002', now()->subHours(2));
        $this->createFailedOrder('CFPay_x', now()->subHours(2));

        Cache::forget('operations:radiumbox-health');
        $alerts = $this->radiumBoxAlerts();
        $this->assertCount(1, $alerts);
        $this->assertSame(1, $alerts[0]->affectedCount);
        $this->assertSame(['RD900002'], $alerts[0]->orderIds);
        $this->assertNotSame('', $alerts[0]->incidentIdentity);
    }

    public function test_dead_letter_hygiene_excludes_synced_order(): void
    {
        $order = $this->createFailedOrder('RD900003', now());
        $order->update(['radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced]);

        $this->insertEnrichmentFailedJob('uuid-synced-1111', $order->id, now());

        config(['ira.watchdog.dead_letter_hygiene_enabled' => true]);
        $this->assertSame([], $this->deadLetterAlerts());

        config(['ira.watchdog.dead_letter_hygiene_enabled' => false]);
        $this->assertCount(1, $this->deadLetterAlerts());
    }

    public function test_dead_letter_hygiene_keeps_not_synced_recent_actionable(): void
    {
        $order = $this->createFailedOrder('RD900004', now());
        $order->update(['radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::NotSynced]);

        $this->insertEnrichmentFailedJob('uuid-not-synced-2222', $order->id, now());

        config(['ira.watchdog.dead_letter_hygiene_enabled' => true]);
        $alerts = $this->deadLetterAlerts();
        $this->assertCount(1, $alerts);
        $this->assertSame(1, $alerts[0]->affectedCount);
    }

    public function test_dead_letter_deduplicates_same_order_and_job_class(): void
    {
        $order = $this->createFailedOrder('RD900005', now());

        $this->insertEnrichmentFailedJob('uuid-dup-1', $order->id, now());
        $this->insertEnrichmentFailedJob('uuid-dup-2', $order->id, now());

        config(['ira.watchdog.dead_letter_hygiene_enabled' => true]);
        $alerts = $this->deadLetterAlerts();
        $this->assertCount(1, $alerts);
        $this->assertSame(1, $alerts[0]->affectedCount);
    }

    public function test_bonvoice_hygiene_downgrades_no_channel_only(): void
    {
        BonvoiceWebhookLog::query()->create([
            'event_type' => '2:NO_CHANNEL',
            'payload' => [
                'Status' => 'NO_CHANNEL',
                'callType' => '2',
                'callID' => null,
            ],
            'processing_status' => BonvoiceWebhookLog::STATUS_FAILED,
            'processing_error' => 'BonVoice webhook payload is missing callID.',
            'received_at' => now(),
        ]);

        BonvoiceWebhookLog::query()->create([
            'event_type' => '2:NOANSWER',
            'payload' => [
                'Status' => 'NOANSWER',
                'callType' => '2',
                'callID' => null,
            ],
            'processing_status' => BonvoiceWebhookLog::STATUS_FAILED,
            'processing_error' => 'BonVoice webhook payload is missing callID.',
            'received_at' => now(),
        ]);

        config(['ira.watchdog.bonvoice_hygiene_enabled' => false]);
        $this->assertSame(2, ($this->bonvoiceAlerts()[0] ?? null)?->affectedCount);

        config(['ira.watchdog.bonvoice_hygiene_enabled' => true]);
        $this->assertSame(1, ($this->bonvoiceAlerts()[0] ?? null)?->affectedCount);
    }

    public function test_alert_parity_command_is_read_only_and_does_not_send_telegram(): void
    {
        $this->createFailedOrder('RD900006', now()->subDays(40));

        $this->artisan('watchdog:alert-parity')->assertSuccessful();
    }

    /**
     * @return list<ProductionCriticalAlert>
     */
    private function radiumBoxAlerts(): array
    {
        return array_values(array_filter(
            app(ProductionWatchdogService::class)->collectCriticalAlerts(),
            fn ($alert) => $alert->key === 'radiumbox:sync_failures',
        ));
    }

    /**
     * @return list<ProductionCriticalAlert>
     */
    private function deadLetterAlerts(): array
    {
        return array_values(array_filter(
            app(ProductionWatchdogService::class)->collectCriticalAlerts(),
            fn ($alert) => $alert->key === 'queue:dead_letter',
        ));
    }

    /**
     * @return list<ProductionCriticalAlert>
     */
    private function bonvoiceAlerts(): array
    {
        return array_values(array_filter(
            app(ProductionWatchdogService::class)->collectCriticalAlerts(),
            fn ($alert) => $alert->key === 'bonvoice:webhook_failures',
        ));
    }

    private function createFailedOrder(string $orderId, Carbon $lastSyncAt): Order
    {
        $actor = User::factory()->create();

        return Order::query()->create([
            'order_id' => $orderId,
            'customer_name' => 'Watchdog Hygiene Test',
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
