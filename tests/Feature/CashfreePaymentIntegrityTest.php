<?php

namespace Tests\Feature;

use App\Enums\CashfreeHistoricalRecoveryDisposition;
use App\Enums\CashfreeWebhookFailureCategory;
use App\Enums\OutboxEventStatus;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\Cashfree\CashfreePaymentIntegrityService;
use App\Services\Cashfree\CashfreeWebhookDeferredOperationsService;
use App\Services\Cashfree\CashfreeWebhookOutboxWriter;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use App\Services\Cashfree\CashfreeWebhookReliabilityMetrics;
use App\Services\DashboardBroadcastService;
use App\Services\Outbox\OutboxProcessorService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentService;
use App\Services\Operations\OperationsCashfreeHealthService;
use App\Services\Operations\OperationsIntegrationHealthService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class CashfreePaymentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashfree.verify_signature' => false]);

        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create([
            'email' => 'superadmin@radium.local',
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->seed(SettingsSeeder::class);

        config(['radiumbox.enabled' => false]);

        Cache::flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function successfulPayload(string $cfPaymentId = '1453002795', string $orderId = 'order_OFR_2'): array
    {
        return [
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'event_time' => '2023-08-01T11:16:10+05:30',
            'data' => [
                'order' => [
                    'order_id' => $orderId,
                    'order_amount' => 2,
                    'order_currency' => 'INR',
                ],
                'payment' => [
                    'cf_payment_id' => $cfPaymentId,
                    'payment_status' => 'SUCCESS',
                    'payment_amount' => 1,
                    'payment_currency' => 'INR',
                    'payment_time' => '2022-12-15T12:20:29+05:30',
                    'payment_group' => 'upi',
                    'bank_reference' => '234928698581',
                ],
                'customer_details' => [
                    'customer_name' => 'Jane Doe',
                    'customer_email' => 'test@gmail.com',
                    'customer_phone' => '9908734801',
                ],
                'payment_gateway_details' => [
                    'gateway_name' => 'CASHFREE',
                    'gateway_order_id' => '1634766330',
                    'gateway_payment_id' => '1504280029',
                ],
            ],
        ];
    }

    private function postSuccessfulWebhook(string $cfPaymentId = '1453002795', string $orderId = 'order_OFR_2'): CashfreeWebhookLog
    {
        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload($cfPaymentId, $orderId))
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);

        return CashfreeWebhookLog::query()->latest('id')->firstOrFail();
    }

    private function createFailedLog(string $cfPaymentId, string $orderId, string $error): CashfreeWebhookLog
    {
        $payload = $this->successfulPayload($cfPaymentId, $orderId);

        return CashfreeWebhookLog::query()->create([
            'cf_payment_id' => $cfPaymentId,
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload),
            'received_at' => now(),
            'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
            'processing_error' => $error,
            'processed_at' => now(),
        ]);
    }

    public function test_dashboard_broadcast_exception_does_not_roll_back_paid_order(): void
    {
        Queue::fake();

        $this->partialMock(DashboardBroadcastService::class, function ($mock): void {
            $mock->shouldReceive('serviceCaseCreated')
                ->once()
                ->andThrow(new RuntimeException('broadcast failed'));
        });

        $log = $this->postSuccessfulWebhook('1453002796', 'order-broadcast-fail');

        $this->assertSame(1, Order::query()->where('cashfree_payment_id', '1453002796')->count());
        $this->assertSame(CashfreeWebhookProcessorService::STATUS_PROCESSED, $log->fresh()->processing_status);
        $this->assertDatabaseHas('outbox_events', [
            'payload->operation' => CashfreeWebhookDeferredOperationsService::OPERATION_DASHBOARD_BROADCAST,
            'status' => OutboxEventStatus::Pending->value,
        ]);
    }

    public function test_radiumbox_exception_does_not_roll_back_paid_order(): void
    {
        Queue::fake();

        $this->partialMock(RadiumBoxOrderEnrichmentService::class, function ($mock): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->andThrow(new RuntimeException('enrichment dispatch failed'));
        });

        $log = $this->postSuccessfulWebhook('1453002797', 'order-enrichment-fail');

        $this->assertSame(1, Order::query()->where('cashfree_payment_id', '1453002797')->count());
        $this->assertSame(CashfreeWebhookProcessorService::STATUS_PROCESSED, $log->fresh()->processing_status);
        $this->assertDatabaseHas('outbox_events', [
            'payload->operation' => CashfreeWebhookDeferredOperationsService::OPERATION_RADIUMBOX_ENRICHMENT,
            'status' => OutboxEventStatus::Pending->value,
        ]);
    }

    public function test_notification_failure_does_not_roll_back_paid_order(): void
    {
        Queue::fake();
        Notification::fake();

        config(['service_case_assignment.automation_grace_period_enabled' => false]);

        Notification::shouldReceive('send')->andThrow(new RuntimeException('notification failed'));

        $log = $this->postSuccessfulWebhook('1453002798', 'order-notification-fail');

        $this->assertSame(1, Order::query()->where('cashfree_payment_id', '1453002798')->count());
        $this->assertSame(CashfreeWebhookProcessorService::STATUS_PROCESSED, $log->fresh()->processing_status);
    }

    public function test_outbox_processor_exception_after_commit_does_not_mark_webhook_failed(): void
    {
        Queue::fake();

        $this->partialMock(OutboxProcessorService::class, function ($mock): void {
            $mock->shouldReceive('process')
                ->once()
                ->andThrow(new RuntimeException('outbox processor unavailable'));
        });

        $log = $this->postSuccessfulWebhook('1453002799', 'order-outbox-dispatch-fail');

        $this->assertSame(1, Order::query()->where('cashfree_payment_id', '1453002799')->count());
        $this->assertSame(CashfreeWebhookProcessorService::STATUS_PROCESSED, $log->fresh()->processing_status);
        $this->assertSame(3, OutboxEvent::query()->where('status', OutboxEventStatus::Pending)->count());
    }

    public function test_duplicate_webhook_does_not_create_duplicate_order(): void
    {
        Queue::fake();

        $payload = $this->successfulPayload('1453002800', 'order-dup');

        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();
        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Incident::query()->count());
        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, 1);
    }

    public function test_reconcile_detects_missing_paid_orders(): void
    {
        $this->createFailedLog(
            cfPaymentId: '5907598359',
            orderId: 'RD3436189',
            error: 'App\\Services\\DashboardPersonalizationService::defaultViewFor(): null user',
        );

        $this->postSuccessfulWebhook('1453002801', 'order-present');

        $report = app(\App\Services\Cashfree\CashfreePaymentIntegrityService::class)->reconcile();

        $this->assertSame(2, $report->successfulCashfreePayments);
        $this->assertSame(1, $report->deskOrders);
        $this->assertSame(1, $report->missingOrdersCount);
        $this->assertSame(1, $report->failedProcessing);
        $this->assertSame(1, $report->paidWithoutDeskOrderCount);
        $this->assertSame(CashfreeHistoricalRecoveryDisposition::Recoverable, $report->missingOrders[0]->recoveryEligibility);
        $this->assertSame('5907598359', $report->missingOrders[0]->cfPaymentId);

        $this->artisan('cashfree:reconcile')
            ->expectsOutputToContain('Successful Cashfree payments: 2')
            ->expectsOutputToContain('Missing orders: 1')
            ->expectsOutputToContain('cf_payment_id=5907598359')
            ->assertSuccessful();
    }

    public function test_paid_without_desk_order_metric_is_exposed(): void
    {
        $this->createFailedLog(
            cfPaymentId: '5910748391',
            orderId: 'RD3436304',
            error: 'Cashfree system user is not configured or inactive.',
        );

        $metrics = app(CashfreeWebhookReliabilityMetrics::class);

        $this->assertSame(1, $metrics->paidWithoutDeskOrderCount());
        $this->assertSame(1, $metrics->dashboardCounts()['cashfree_paid_without_desk_order']);
    }

    public function test_critical_payment_persistence_failure_still_marks_webhook_failed(): void
    {
        $payload = $this->successfulPayload('1453002802', 'order-critical-fail');
        unset($payload['data']['order']['order_id']);

        $this->postJson('/api/webhooks/cashfree', $payload)
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);

        $log = CashfreeWebhookLog::query()->firstOrFail();
        $this->assertSame(CashfreeWebhookProcessorService::STATUS_FAILED, $log->processing_status);
        $this->assertSame(0, Order::query()->count());
    }

    public function test_recovered_failed_webhook_is_not_counted_as_active(): void
    {
        $payload = $this->successfulPayload('5900000200', 'RD-RECOVERED-1');

        Order::query()->create([
            'order_id' => 'RD-RECOVERED-1',
            'cashfree_payment_id' => '5900000200',
            'status' => 'active',
            'created_by' => User::query()->firstOrFail()->id,
        ]);

        $this->createFailedLog('5900000200', 'RD-RECOVERED-1', 'Historical processing failure');

        $classification = app(CashfreePaymentIntegrityService::class)->classifyFailedWebhooks();

        $this->assertSame(1, $classification->totalFailed);
        $this->assertSame(0, $classification->activeFailedWebhooks);
        $this->assertSame(1, $classification->historicalResolvedFailures);
        $this->assertSame(CashfreeWebhookFailureCategory::PaymentExistsInDesk, $classification->records[0]->category);
    }

    public function test_duplicate_failed_webhook_is_counted_as_historical(): void
    {
        $payload = $this->successfulPayload('5900000201', 'RD-DUP-1');
        $agent = User::query()->firstOrFail();
        $order = Order::query()->create([
            'order_id' => 'RD-DUP-PROCESSED',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);
        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-DUP-1',
            'category' => 'General',
            'source' => 'call',
            'title' => 'Duplicate webhook test',
            'description' => 'Duplicate webhook test.',
            'status' => 'open',
            'created_by' => $agent->id,
        ]);

        CashfreeWebhookLog::query()->create([
            'cf_payment_id' => '5900000201',
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload),
            'received_at' => now()->subMinute(),
            'processing_status' => CashfreeWebhookProcessorService::STATUS_PROCESSED,
            'processed_at' => now()->subMinute(),
            'incident_id' => $incident->id,
        ]);

        $this->createFailedLog('5900000201', 'RD-DUP-1', 'Duplicate webhook attempt failed');

        $classification = app(CashfreePaymentIntegrityService::class)->classifyFailedWebhooks();

        $this->assertSame(0, $classification->activeFailedWebhooks);
        $this->assertSame(1, $classification->historicalResolvedFailures);
        $this->assertSame(CashfreeWebhookFailureCategory::DuplicateSucceeded, $classification->records[0]->category);
    }

    public function test_paid_missing_order_is_counted_as_active_failure(): void
    {
        $this->createFailedLog(
            cfPaymentId: '5900000202',
            orderId: 'RD-MISSING-1',
            error: 'Cashfree system user is not configured or inactive.',
        );

        $classification = app(CashfreePaymentIntegrityService::class)->classifyFailedWebhooks();

        $this->assertSame(1, $classification->activeFailedWebhooks);
        $this->assertSame(1, app(CashfreePaymentIntegrityService::class)->paidWithoutDeskOrderCount());
        $this->assertTrue(app(CashfreePaymentIntegrityService::class)->requiresCashfreeHealthAlert());
        $this->assertSame(CashfreeWebhookFailureCategory::Unresolved, $classification->records[0]->category);
    }

    public function test_dashboard_shows_healthy_cashfree_when_only_historical_failures_remain(): void
    {
        Order::query()->create([
            'order_id' => 'RD-RECOVERED-DASH',
            'cashfree_payment_id' => '5900000210',
            'status' => 'active',
            'created_by' => User::query()->firstOrFail()->id,
        ]);

        $this->createFailedLog('5900000210', 'RD-RECOVERED-DASH', 'Historical processing failure');

        $health = app(OperationsCashfreeHealthService::class)->widget(useCache: false);
        $cashfreeCard = collect(app(OperationsIntegrationHealthService::class)->cards())
            ->firstWhere('key', 'cashfree');

        $this->assertTrue($health['is_healthy']);
        $this->assertSame(0, $health['active_failed_webhooks']);
        $this->assertSame(1, $health['historical_resolved_failures']);
        $this->assertSame('healthy', $cashfreeCard['status']);
        $this->assertStringContainsString('historical failure(s) archived', (string) $cashfreeCard['detail']);
    }

    public function test_dashboard_alerts_when_paid_order_is_missing(): void
    {
        $this->createFailedLog(
            cfPaymentId: '5900000203',
            orderId: 'RD-MISSING-2',
            error: 'Historical bug blocked order creation',
        );

        $health = app(OperationsCashfreeHealthService::class)->widget(useCache: false);
        $cashfreeCard = collect(app(OperationsIntegrationHealthService::class)->cards())
            ->firstWhere('key', 'cashfree');

        $this->assertFalse($health['is_healthy']);
        $this->assertSame(1, $health['paid_without_desk_order']);
        $this->assertSame('failed', $cashfreeCard['status']);
    }
}
