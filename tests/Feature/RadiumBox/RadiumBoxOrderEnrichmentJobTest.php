<?php

namespace Tests\Feature\RadiumBox;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Infrastructure\Queue\QueueMetricsService;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\DeviceModel;
use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\Exceptions\RadiumBoxEnrichmentRetryException;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RadiumBoxOrderEnrichmentJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
            'radiumbox.timeout_seconds' => 5,
            'radiumbox.connect_timeout_seconds' => 3,
        ]);
    }

    public function test_background_job_enriches_order_from_radiumbox(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => 'M250546898',
                        'product_name' => 'Access FM220U L1',
                        'activation_year' => '2024',
                        'warranty' => 'Active until 2027',
                        'amc' => 'Not enrolled',
                    ],
                ],
            ]),
        ]);

        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD3433380',
            'serial_number' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);

        Queue::fake();
        app(RadiumBoxOrderEnrichmentService::class)->dispatch($order);
        $this->assertSame(RadiumBoxEnrichmentSyncStatus::Pending, $syncStore->status($order->id));
        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class);

        (new RadiumBoxOrderEnrichmentJob($order->id))
            ->handle(
                app(RadiumBoxOrderEnrichmentService::class),
                app(QueueMetricsService::class),
            );

        $order->refresh();

        $this->assertSame('M250546898', $order->serial_number);
        $this->assertSame('Access FM220U L1', $order->device_model);
        $this->assertSame(RadiumBoxEnrichmentSyncStatus::Synced, $syncStore->status($order->id));
        $this->assertSame('2024', $syncStore->metadata($order->id)['activation_year'] ?? null);
        $this->assertSame('Active until 2027', $syncStore->metadata($order->id)['warranty'] ?? null);
        $this->assertSame('Not enrolled', $syncStore->metadata($order->id)['amc'] ?? null);
    }

    public function test_background_job_does_not_overwrite_manual_serial_or_device_model(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => 'M250546898',
                        'product_name' => 'Access FM220U L1',
                    ],
                ],
            ]),
        ]);

        $agent = User::factory()->create();
        $deviceModel = DeviceModel::query()->create([
            'name' => 'Manual Model',
            'code' => 'manual-model',
            'is_active' => true,
        ]);

        $order = Order::query()->create([
            'order_id' => 'RD3433380',
            'serial_number' => 'LOCAL-SERIAL-1',
            'device_model' => 'Manual Model',
            'device_model_id' => $deviceModel->id,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        RadiumBoxOrderEnrichmentJob::dispatchSync($order->id);

        $order->refresh();

        $this->assertSame('LOCAL-SERIAL-1', $order->serial_number);
        $this->assertSame('Manual Model', $order->device_model);
        $this->assertSame($deviceModel->id, $order->device_model_id);

        Http::assertNothingSent();
    }

    public function test_retriable_failure_schedules_retry_and_marks_failed_after_exhaustion(): void
    {
        Log::spy();

        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response(null, 500),
        ]);

        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD3433380',
            'serial_number' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $job = new RadiumBoxOrderEnrichmentJob($order->id);

        try {
            $job->handle(
                app(RadiumBoxOrderEnrichmentService::class),
                app(QueueMetricsService::class),
            );
            $this->fail('Expected retriable enrichment failure.');
        } catch (RadiumBoxEnrichmentRetryException) {
            //
        }

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'RadiumBox order enrichment attempt completed.'
                    && ($context['result'] ?? null) === 'retry_scheduled'
                    && ($context['attempt'] ?? null) === 1
                    && isset($context['duration_ms']);
            });

        $job->failed(new \RuntimeException('RadiumBox API request failed with HTTP 500.'));

        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);
        $this->assertSame(RadiumBoxEnrichmentSyncStatus::Failed, $syncStore->status($order->id));
    }

    public function test_retry_order_enrichment_redispatches_job(): void
    {
        Queue::fake();

        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD3433380',
            'serial_number' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);
        $syncStore->markFailed($order->id, 'Previous failure');

        app(RadiumBoxOrderEnrichmentService::class)->retryOrderEnrichment($order);

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, function (RadiumBoxOrderEnrichmentJob $job) use ($order): bool {
            return $job->orderId === $order->id;
        });

        $this->assertSame(RadiumBoxEnrichmentSyncStatus::Pending, $syncStore->status($order->id));
    }

    public function test_order_not_found_is_marked_synced_without_retry(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 404,
                'message' => 'RD Order not found',
            ]),
        ]);

        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD999999999',
            'serial_number' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        RadiumBoxOrderEnrichmentJob::dispatchSync($order->id);

        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);
        $this->assertSame(RadiumBoxEnrichmentSyncStatus::Synced, $syncStore->status($order->id));
        $this->assertSame('order_not_found', $syncStore->metadata($order->id)['lookup_result'] ?? null);
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }
}
