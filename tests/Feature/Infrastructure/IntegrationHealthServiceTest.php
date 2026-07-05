<?php

namespace Tests\Feature\Infrastructure;

use App\Infrastructure\IntegrationHealth\IntegrationHealthService;
use App\Infrastructure\IntegrationHealth\Probes\RadiumBoxIntegrationHealthProbe;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntegrationHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_cashfree_health_details_include_webhook_metrics(): void
    {
        $successfulPayload = [
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'data' => [
                'order' => ['order_id' => 'RD-HEALTH-ACTIVE'],
                'payment' => [
                    'cf_payment_id' => '5900000001',
                    'payment_status' => 'SUCCESS',
                ],
            ],
        ];

        CashfreeWebhookLog::query()->create([
            'webhook_version' => '2023-08-01',
            'cf_payment_id' => '5900000001',
            'request_headers' => [],
            'request_payload' => $successfulPayload,
            'raw_body' => '{}',
            'received_at' => now()->subHour(),
            'source_ip' => '127.0.0.1',
            'user_agent' => 'test',
            'processing_status' => CashfreeWebhookProcessorService::STATUS_PROCESSED,
            'processed_at' => now()->subMinutes(30),
        ]);

        Order::query()->create([
            'order_id' => 'RD-HEALTH-ACTIVE',
            'cashfree_payment_id' => '5900000001',
            'status' => 'active',
            'created_by' => User::factory()->create()->id,
        ]);

        CashfreeWebhookLog::query()->create([
            'webhook_version' => '2023-08-01',
            'cf_payment_id' => '5900000002',
            'request_headers' => [],
            'request_payload' => [
                'type' => 'PAYMENT_SUCCESS_WEBHOOK',
                'data' => [
                    'order' => ['order_id' => 'RD-HEALTH-ACTIVE-FAIL'],
                    'payment' => [
                        'cf_payment_id' => '5900000002',
                        'payment_status' => 'SUCCESS',
                    ],
                ],
            ],
            'raw_body' => '{}',
            'received_at' => now(),
            'source_ip' => '127.0.0.1',
            'user_agent' => 'test',
            'processing_status' => CashfreeWebhookProcessorService::STATUS_FAILED,
            'processing_error' => 'Order persistence failed',
            'processed_at' => now(),
        ]);

        $details = app(IntegrationHealthService::class)->cashfree();

        $this->assertNotNull($details->lastWebhookAt);
        $this->assertNotNull($details->lastSuccessfulWebhookAt);
        $this->assertSame(1, $details->failedWebhooks);
        $this->assertSame(1, $details->activeFailedWebhooks);
        $this->assertSame(0, $details->historicalResolvedFailures);
        $this->assertSame(1, $details->paidWithoutDeskOrderCount);
        $this->assertFalse($details->isHealthy());
    }

    public function test_cashfree_health_treats_recovered_duplicate_failure_as_historical(): void
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-HEALTH-DUP',
            'cashfree_payment_id' => '5900000100',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);
        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-HEALTH-DUP',
            'category' => 'General',
            'source' => 'call',
            'title' => 'Health duplicate test',
            'description' => 'Health duplicate test.',
            'status' => 'open',
            'created_by' => $agent->id,
        ]);

        $payload = [
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'data' => [
                'order' => ['order_id' => 'RD-HEALTH-DUP'],
                'payment' => [
                    'cf_payment_id' => '5900000100',
                    'payment_status' => 'SUCCESS',
                ],
            ],
        ];

        CashfreeWebhookLog::query()->create([
            'webhook_version' => '2023-08-01',
            'cf_payment_id' => '5900000100',
            'request_headers' => [],
            'request_payload' => $payload,
            'raw_body' => '{}',
            'received_at' => now()->subHour(),
            'source_ip' => '127.0.0.1',
            'user_agent' => 'test',
            'processing_status' => CashfreeWebhookProcessorService::STATUS_PROCESSED,
            'processed_at' => now()->subMinutes(30),
            'incident_id' => $incident->id,
        ]);

        CashfreeWebhookLog::query()->create([
            'webhook_version' => '2023-08-01',
            'cf_payment_id' => '5900000100',
            'request_headers' => [],
            'request_payload' => $payload,
            'raw_body' => '{}',
            'received_at' => now(),
            'source_ip' => '127.0.0.1',
            'user_agent' => 'test',
            'processing_status' => CashfreeWebhookProcessorService::STATUS_FAILED,
            'processing_error' => 'Duplicate attempt failed before idempotency guard',
            'processed_at' => now(),
        ]);

        $details = app(IntegrationHealthService::class)->cashfree();

        $this->assertSame(1, $details->failedWebhooks);
        $this->assertSame(0, $details->activeFailedWebhooks);
        $this->assertSame(1, $details->historicalResolvedFailures);
        $this->assertTrue($details->isHealthy());
    }

    public function test_radiumbox_health_details_include_sync_counts(): void
    {
        config(['radiumbox.enabled' => true]);

        RadiumBoxIntegrationHealthProbe::recordAttempt('synced', 120.5);

        $agent = User::factory()->create();
        $pending = Order::query()->create([
            'order_id' => 'RD-HEALTH-PENDING',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);
        $failed = Order::query()->create([
            'order_id' => 'RD-HEALTH-FAILED',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);
        $syncStore->markPending($pending->id);
        $syncStore->markFailed($failed->id, 'Timeout');

        $details = app(IntegrationHealthService::class)->radiumbox();

        $this->assertNotNull($details->lastSuccessfulSyncAt);
        $this->assertSame(1, $details->pendingSyncs);
        $this->assertSame(1, $details->failedSyncs);
        $this->assertNotNull($details->averageResponseTimeMs);
    }

    public function test_queue_health_details_include_oldest_pending_job(): void
    {
        $oldest = now()->subMinutes(15)->getTimestamp();

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $oldest,
            'created_at' => $oldest,
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) str()->uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Test failure',
            'failed_at' => now(),
        ]);

        $details = app(IntegrationHealthService::class)->queue();

        $this->assertSame(1, $details->pendingJobs);
        $this->assertSame(1, $details->failedJobs);
        $this->assertNotNull($details->oldestPendingJobAt);
    }

    public function test_all_returns_structured_payload_for_each_integration(): void
    {
        $payload = app(IntegrationHealthService::class)->all();

        $this->assertArrayHasKey('cashfree', $payload);
        $this->assertArrayHasKey('radiumbox', $payload);
        $this->assertArrayHasKey('queue', $payload);
        $this->assertArrayHasKey('failed_webhooks', $payload['cashfree']);
        $this->assertArrayHasKey('active_failed_webhooks', $payload['cashfree']);
        $this->assertArrayHasKey('historical_resolved_failures', $payload['cashfree']);
        $this->assertArrayHasKey('pending_syncs', $payload['radiumbox']);
        $this->assertArrayHasKey('oldest_pending_job_at', $payload['queue']);
    }
}
