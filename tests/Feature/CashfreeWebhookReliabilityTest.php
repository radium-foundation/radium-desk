<?php

namespace Tests\Feature;

use App\Enums\OutboxEventStatus;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\AuditLog;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Cashfree\CashfreeWebhookDeferredOperationsService;
use App\Services\Cashfree\CashfreeWebhookOutboxWriter;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use App\Services\Cashfree\CashfreeWebhookReliabilityMetrics;
use App\Services\DashboardBroadcastService;
use App\Services\Outbox\OutboxProcessorService;
use App\Services\ServiceCaseAutomationMonitorService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class CashfreeWebhookReliabilityTest extends TestCase
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

    private function postSuccessfulWebhook(string $cfPaymentId = '1453002795', string $orderId = 'order_OFR_2'): void
    {
        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload($cfPaymentId, $orderId))
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_automation_monitor_failure_does_not_roll_back_order_creation(): void
    {
        Queue::fake();

        $this->partialMock(ServiceCaseAutomationMonitorService::class, function ($mock): void {
            $mock->shouldReceive('recordPaymentReceived')
                ->once()
                ->andThrow(new RuntimeException('monitor failed'));
        });

        $this->postSuccessfulWebhook();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Incident::query()->count());

        $log = CashfreeWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame(CashfreeWebhookProcessorService::STATUS_PROCESSED, $log->processing_status);

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => CashfreeWebhookOutboxWriter::EVENT_TYPE,
            'status' => OutboxEventStatus::Pending->value,
            'payload->operation' => CashfreeWebhookDeferredOperationsService::OPERATION_AUTOMATION_MONITOR,
        ]);
    }

    public function test_dashboard_broadcast_failure_does_not_roll_back_order_creation(): void
    {
        Queue::fake();

        $this->partialMock(DashboardBroadcastService::class, function ($mock): void {
            $mock->shouldReceive('serviceCaseCreated')
                ->once()
                ->andThrow(new RuntimeException('broadcast failed'));
        });

        $this->postSuccessfulWebhook('1453002796', 'order-broadcast-fail');

        $this->assertSame(1, Order::query()->where('cashfree_payment_id', '1453002796')->count());
        $this->assertSame(1, Incident::query()->count());

        $this->assertDatabaseHas('outbox_events', [
            'payload->operation' => CashfreeWebhookDeferredOperationsService::OPERATION_DASHBOARD_BROADCAST,
            'status' => OutboxEventStatus::Pending->value,
        ]);
    }

    public function test_audit_failure_during_payment_received_does_not_roll_back_order_creation(): void
    {
        Queue::fake();

        $auditLogService = app(AuditLogService::class);

        $this->mock(AuditLogService::class, function ($mock) use ($auditLogService): void {
            $mock->shouldReceive('log')
                ->andReturnUsing(function (
                    ?int $userId,
                    string $event,
                    $auditable,
                    ?array $oldValues = null,
                    ?array $newValues = null,
                    $request = null,
                ) use ($auditLogService) {
                    if ($event === ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED) {
                        throw new RuntimeException('audit failed');
                    }

                    return $auditLogService->log(
                        userId: $userId,
                        event: $event,
                        auditable: $auditable,
                        oldValues: $oldValues,
                        newValues: $newValues,
                        request: $request,
                    );
                });
        });

        $this->postSuccessfulWebhook('1453002797', 'order-audit-fail');

        $this->assertSame(1, Order::query()->where('cashfree_payment_id', '1453002797')->count());
        $this->assertSame(1, Incident::query()->count());
        $this->assertSame(0, AuditLog::query()
            ->where('event', ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED)
            ->count());

        $this->assertDatabaseHas('outbox_events', [
            'payload->operation' => CashfreeWebhookDeferredOperationsService::OPERATION_AUTOMATION_MONITOR,
            'status' => OutboxEventStatus::Pending->value,
        ]);
    }

    public function test_critical_database_failure_still_rolls_back_order_creation(): void
    {
        $payload = $this->successfulPayload('1453002798', 'order-critical-fail');
        unset($payload['data']['order']['order_id']);

        $this->postJson('/api/webhooks/cashfree', $payload)
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);

        $log = CashfreeWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame(CashfreeWebhookProcessorService::STATUS_FAILED, $log->processing_status);
        $this->assertStringContainsString('order_id', (string) $log->processing_error);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Incident::query()->count());
        $this->assertSame(0, OutboxEvent::query()->count());
    }

    public function test_existing_webhook_behavior_remains_unchanged(): void
    {
        Queue::fake();

        $this->postSuccessfulWebhook();

        $log = CashfreeWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame(CashfreeWebhookProcessorService::STATUS_PROCESSED, $log->processing_status);
        $this->assertNotNull($log->incident_id);

        $order = Order::query()->where('cashfree_payment_id', '1453002795')->first();
        $this->assertNotNull($order);

        $this->assertSame(1, AuditLog::query()
            ->where('event', ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED)
            ->count());

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, function (RadiumBoxOrderEnrichmentJob $job) use ($order): bool {
            return $job->orderId === $order->id;
        });

        $this->assertSame(3, OutboxEvent::query()->where('status', OutboxEventStatus::Completed)->count());
    }

    public function test_deferred_failure_leaves_outbox_event_for_retry(): void
    {
        $auditLogService = app(AuditLogService::class);

        $this->mock(AuditLogService::class, function ($mock) use ($auditLogService): void {
            $mock->shouldReceive('log')
                ->andReturnUsing(function (
                    ?int $userId,
                    string $event,
                    $auditable,
                    ?array $oldValues = null,
                    ?array $newValues = null,
                    $request = null,
                ) use ($auditLogService) {
                    if ($event === ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED) {
                        throw new RuntimeException('audit failed');
                    }

                    return $auditLogService->log(
                        userId: $userId,
                        event: $event,
                        auditable: $auditable,
                        oldValues: $oldValues,
                        newValues: $newValues,
                        request: $request,
                    );
                });
        });

        Queue::fake();

        $this->postSuccessfulWebhook('1453002799', 'order-retry-success');

        $metrics = app(CashfreeWebhookReliabilityMetrics::class)->snapshot();
        $this->assertSame(1, $metrics->ordersCreated);
        $this->assertSame(1, $metrics->outboxPending);
        $this->assertSame(0, $metrics->outboxFailed);

        $event = OutboxEvent::query()
            ->where('payload->operation', CashfreeWebhookDeferredOperationsService::OPERATION_AUTOMATION_MONITOR)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(OutboxEventStatus::Pending, $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertNotNull($event->last_error);
    }

    public function test_outbox_retry_records_successful_retry_metric(): void
    {
        Queue::fake();

        $this->postSuccessfulWebhook('1453002801', 'order-retry-job');

        $event = OutboxEvent::query()
            ->where('payload->operation', CashfreeWebhookDeferredOperationsService::OPERATION_AUTOMATION_MONITOR)
            ->firstOrFail();

        $event->update([
            'status' => OutboxEventStatus::Pending,
            'attempts' => 1,
            'available_at' => now()->subSecond(),
        ]);

        app(OutboxProcessorService::class)->process();

        $metrics = app(CashfreeWebhookReliabilityMetrics::class)->snapshot();
        $this->assertSame(0, $metrics->outboxPending);
        $this->assertGreaterThanOrEqual(2, $metrics->outboxRetryCount);
        $event->refresh();
        $this->assertSame(OutboxEventStatus::Completed, $event->status);
    }

    public function test_reliability_metrics_are_available_for_automation_dashboard(): void
    {
        Queue::fake();

        $this->postSuccessfulWebhook('1453002800', 'order-metrics');

        $counts = app(CashfreeWebhookReliabilityMetrics::class)->dashboardCounts();

        $this->assertSame(1, $counts['cashfree_orders_created']);
        $this->assertSame(0, $counts['cashfree_outbox_pending']);
        $this->assertSame(0, $counts['cashfree_outbox_failed']);
        $this->assertSame(3, $counts['cashfree_outbox_completed_today']);
        $this->assertSame(0, $counts['cashfree_paid_without_desk_order']);
    }
}
