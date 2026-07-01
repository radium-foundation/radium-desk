<?php

namespace Tests\Feature;

use App\Jobs\ProcessCashfreeWebhookDeferredOperationJob;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\AuditLog;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use App\Services\Cashfree\CashfreeWebhookReliabilityMetrics;
use App\Services\DashboardBroadcastService;
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

        Queue::assertPushed(ProcessCashfreeWebhookDeferredOperationJob::class, function (ProcessCashfreeWebhookDeferredOperationJob $job): bool {
            return $job->operation === 'automation_monitor';
        });
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

        Queue::assertPushed(ProcessCashfreeWebhookDeferredOperationJob::class, function (ProcessCashfreeWebhookDeferredOperationJob $job): bool {
            return $job->operation === 'dashboard_broadcast';
        });
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

        Queue::assertPushed(ProcessCashfreeWebhookDeferredOperationJob::class, function (ProcessCashfreeWebhookDeferredOperationJob $job): bool {
            return $job->operation === 'automation_monitor';
        });
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
    }

    public function test_deferred_failure_records_metrics_and_dispatches_retry_job(): void
    {
        $metrics = app(CashfreeWebhookReliabilityMetrics::class);
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

        $snapshot = $metrics->snapshot();
        $this->assertSame(1, $snapshot->ordersCreated);
        $this->assertSame(1, $snapshot->deferredTaskFailures);
        $this->assertSame(0, $snapshot->successfulRetries);

        Queue::assertPushed(ProcessCashfreeWebhookDeferredOperationJob::class, function (ProcessCashfreeWebhookDeferredOperationJob $job): bool {
            return $job->operation === 'automation_monitor';
        });
    }

    public function test_deferred_retry_job_records_successful_retry_metric(): void
    {
        $metrics = app(CashfreeWebhookReliabilityMetrics::class);

        $this->postSuccessfulWebhook('1453002801', 'order-retry-job');

        $order = Order::query()->where('cashfree_payment_id', '1453002801')->firstOrFail();
        $incidentId = (int) CashfreeWebhookLog::query()->value('incident_id');
        $actorId = (int) User::query()->where('email', 'superadmin@radium.local')->value('id');

        $job = new ProcessCashfreeWebhookDeferredOperationJob(
            operation: 'automation_monitor',
            orderId: $order->id,
            incidentId: $incidentId,
            actorId: $actorId,
        );

        $job->handle(
            app(\App\Services\Cashfree\CashfreeWebhookDeferredOperationsService::class),
            $metrics,
        );

        $this->assertSame(1, $metrics->snapshot()->successfulRetries);
    }

    public function test_reliability_metrics_are_available_for_automation_dashboard(): void
    {
        $this->postSuccessfulWebhook('1453002800', 'order-metrics');

        $counts = app(CashfreeWebhookReliabilityMetrics::class)->dashboardCounts();

        $this->assertSame(1, $counts['cashfree_orders_created']);
        $this->assertSame(0, $counts['cashfree_deferred_failures']);
        $this->assertSame(0, $counts['cashfree_deferred_retries']);
    }
}
