<?php

namespace Tests\Feature\RadiumBox;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Infrastructure\Queue\QueueMetricsService;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\DeviceModel;
use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase 5 budgets: duplicate dispatch suppression, already-enriched skip, lookup cache.
 */
class RadiumBoxQueuePhase5PerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
            'radiumbox.admin_fallback_enabled' => true,
            'radiumbox.timeout_seconds' => 5,
            'radiumbox.connect_timeout_seconds' => 3,
            'radiumbox.background_lookup_cache_seconds' => 300,
            'cache.default' => 'array',
        ]);

        Cache::flush();
    }

    public function test_duplicate_dispatch_while_pending_does_not_enqueue_second_job(): void
    {
        Queue::fake();

        $order = $this->createOrderNeedingEnrichment();
        $service = app(RadiumBoxOrderEnrichmentService::class);

        $this->assertTrue($service->dispatch($order));
        $this->assertFalse($service->dispatch($order->fresh()));

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, 1);
        $this->assertSame(
            RadiumBoxEnrichmentSyncStatus::Pending,
            app(RadiumBoxOrderEnrichmentSyncStore::class)->status($order->id),
        );
    }

    public function test_already_enriched_process_skips_http_and_attempt_increment(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => 'SHOULD-NOT-APPLY',
                        'product_name' => 'Ignored Model',
                    ],
                ],
            ]),
        ]);

        $deviceModel = DeviceModel::query()->create([
            'name' => 'Manual Model',
            'code' => 'manual-model',
            'is_active' => true,
        ]);

        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-ALREADY-ENRICHED',
            'serial_number' => 'LOCAL-SERIAL-1',
            'device_model' => 'Manual Model',
            'device_model_id' => $deviceModel->id,
            'product_name' => 'Manual Model',
            'service_history' => [['ref' => 'prior']],
            'status' => 'active',
            'created_by' => $agent->id,
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Pending,
            'radiumbox_sync_attempts' => 2,
        ]);

        $started = hrtime(true);
        (new RadiumBoxOrderEnrichmentJob($order->id))->handle(
            app(RadiumBoxOrderEnrichmentService::class),
            app(QueueMetricsService::class),
        );
        $elapsedMs = (hrtime(true) - $started) / 1e6;

        Http::assertNothingSent();

        $order->refresh();
        $this->assertSame('LOCAL-SERIAL-1', $order->serial_number);
        $this->assertSame(2, (int) $order->radiumbox_sync_attempts);
        $this->assertSame(
            RadiumBoxEnrichmentSyncStatus::Synced,
            app(RadiumBoxOrderEnrichmentSyncStore::class)->status($order->id),
        );
        $this->assertLessThan(
            50,
            $elapsedMs,
            "Already-enriched process should be cheap locally, got {$elapsedMs}ms",
        );
    }

    public function test_background_lookup_cache_avoids_second_http_for_same_order(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => 'M250546898',
                        'product_name' => 'Access FM220U L1',
                        'service_history' => [['ticket' => 'T1']],
                    ],
                ],
            ]),
        ]);

        $order = $this->createOrderNeedingEnrichment('RD-CACHE');

        (new RadiumBoxOrderEnrichmentJob($order->id))->handle(
            app(RadiumBoxOrderEnrichmentService::class),
            app(QueueMetricsService::class),
        );

        // Simulate a duplicate job for the same RadiumBox order id within the TTL.
        $order->update([
            'serial_number' => null,
            'device_model' => null,
            'device_model_id' => null,
            'product_name' => null,
            'service_history' => null,
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Pending,
        ]);

        (new RadiumBoxOrderEnrichmentJob($order->id))->handle(
            app(RadiumBoxOrderEnrichmentService::class),
            app(QueueMetricsService::class),
        );

        Http::assertSentCount(1);
        $this->assertSame('M250546898', $order->fresh()->serial_number);
    }

    public function test_recovery_preserves_attempt_count_across_redispatch(): void
    {
        Queue::fake();

        $order = $this->createOrderNeedingEnrichment();
        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);

        $syncStore->markFailed($order->id, 'Previous failure');
        $order->update([
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Failed,
            'radiumbox_sync_attempts' => 7,
            'cashfree_payment_id' => 'cf_pay_phase3',
            'created_at' => now()->subHours(2),
            'radiumbox_last_sync_at' => now()->subHours(2),
        ]);

        app(RadiumBoxOrderEnrichmentService::class)->retryOrderEnrichment($order->fresh());

        $this->assertSame(7, $syncStore->attemptCount($order->id));
        $this->assertSame(RadiumBoxEnrichmentSyncStatus::Pending, $syncStore->status($order->id));
        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, 1);
    }

    public function test_dispatch_if_needed_skips_fully_enriched_orders(): void
    {
        Queue::fake();

        $deviceModel = DeviceModel::query()->create([
            'name' => 'Complete Model',
            'code' => 'complete-model',
            'is_active' => true,
        ]);

        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-COMPLETE',
            'serial_number' => 'SN-COMPLETE',
            'device_model' => 'Complete Model',
            'device_model_id' => $deviceModel->id,
            'product_name' => 'Complete Model',
            'service_history' => [['ref' => 'ok']],
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->assertFalse(app(RadiumBoxOrderEnrichmentService::class)->dispatchIfNeeded($order));
        Queue::assertNothingPushed();
    }

    private function createOrderNeedingEnrichment(string $orderId = 'RD-PHASE3'): Order
    {
        $agent = User::factory()->create();

        return Order::query()->create([
            'order_id' => $orderId.'-'.uniqid(),
            'serial_number' => null,
            'device_model' => null,
            'product_name' => null,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);
    }
}
