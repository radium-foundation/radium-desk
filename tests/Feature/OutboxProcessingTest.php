<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OrderStatus;
use App\Enums\OutboxEventStatus;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\Cashfree\CashfreeWebhookDeferredOperationsService;
use App\Services\Cashfree\CashfreeWebhookOutboxWriter;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use App\Services\DashboardBroadcastService;
use App\Services\Outbox\OutboxProcessorService;
use App\Services\ServiceCaseAutomationMonitorService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OutboxProcessingTest extends TestCase
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
    private function successfulPayload(string $cfPaymentId, string $orderId): array
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
                ],
                'customer_details' => [
                    'customer_name' => 'Jane Doe',
                    'customer_email' => 'test@gmail.com',
                    'customer_phone' => '9908734801',
                ],
            ],
        ];
    }

    private function disableImmediateOutboxProcessing(): void
    {
        $noop = Mockery::mock(OutboxProcessorService::class);
        $noop->shouldReceive('process')->andReturn(0);
        $this->app->instance(OutboxProcessorService::class, $noop);
    }

    private function restoreOutboxProcessor(): OutboxProcessorService
    {
        $this->app->forgetInstance(OutboxProcessorService::class);

        return app(OutboxProcessorService::class);
    }

    /**
     * @return array{order: Order, incident: Incident, actor: User}
     */
    private function createOrderIncidentContext(): array
    {
        $actor = User::query()->where('email', 'superadmin@radium.local')->firstOrFail();

        $order = Order::query()->create([
            'order_id' => 'order-outbox-test',
            'customer_name' => 'Jane Doe',
            'customer_email' => 'test@gmail.com',
            'customer_phone' => '9908734801',
            'cashfree_payment_id' => '1453003999',
            'status' => OrderStatus::Active,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-OUTBOX-TEST',
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Outbox test incident',
            'description' => 'Created for outbox tests.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        return [
            'order' => $order,
            'incident' => $incident,
            'actor' => $actor,
        ];
    }

    private function createPendingOutboxEvent(
        string $operation,
        Order $order,
        Incident $incident,
        User $actor,
        int $aggregateOffset = 0,
    ): OutboxEvent {
        return OutboxEvent::query()->create([
            'event_type' => CashfreeWebhookOutboxWriter::EVENT_TYPE,
            'aggregate_type' => CashfreeWebhookOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $incident->id + $aggregateOffset,
            'payload' => [
                'operation' => $operation,
                'order_id' => $order->id,
                'incident_id' => $incident->id,
                'actor_id' => $actor->id,
            ],
            'status' => OutboxEventStatus::Pending,
            'attempts' => 0,
            'available_at' => now(),
            'idempotency_key' => sprintf(
                'cashfree.webhook.deferred.%s.%d',
                $operation,
                $incident->id + $aggregateOffset,
            ),
        ]);
    }

    public function test_outbox_events_are_written_during_webhook_transaction(): void
    {
        Queue::fake();
        $this->disableImmediateOutboxProcessing();

        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload('1453003001', 'order-outbox-write'))
            ->assertOk();

        $this->assertSame(3, OutboxEvent::query()->count());
        $this->assertSame(3, OutboxEvent::query()->where('status', OutboxEventStatus::Pending)->count());
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => CashfreeWebhookOutboxWriter::EVENT_TYPE,
            'aggregate_type' => CashfreeWebhookOutboxWriter::AGGREGATE_TYPE,
        ]);
    }

    public function test_order_commits_even_when_outbox_processor_later_fails(): void
    {
        Queue::fake();

        $this->partialMock(ServiceCaseAutomationMonitorService::class, function ($mock): void {
            $mock->shouldReceive('recordPaymentReceived')
                ->andThrow(new RuntimeException('processor failed'));
        });

        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload('1453003002', 'order-processor-fail'))
            ->assertOk();

        $this->assertSame(1, Order::query()->where('cashfree_payment_id', '1453003002')->count());
        $this->assertSame(1, Incident::query()->count());

        $log = CashfreeWebhookLog::query()->first();
        $this->assertSame(CashfreeWebhookProcessorService::STATUS_PROCESSED, $log?->processing_status);
        $this->assertGreaterThan(0, OutboxEvent::query()->where('status', OutboxEventStatus::Pending)->count());
    }

    public function test_outbox_processor_successfully_completes_pending_events(): void
    {
        Queue::fake();
        $this->disableImmediateOutboxProcessing();

        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload('1453003003', 'order-success'))
            ->assertOk();

        $this->assertSame(3, OutboxEvent::query()->where('status', OutboxEventStatus::Pending)->count());

        $processed = $this->restoreOutboxProcessor()->process();

        $this->assertSame(3, $processed);
        $this->assertSame(3, OutboxEvent::query()->where('status', OutboxEventStatus::Completed)->count());
    }

    public function test_outbox_processor_retries_failed_events_with_backoff(): void
    {
        Queue::fake();

        ['order' => $order, 'incident' => $incident, 'actor' => $actor] = $this->createOrderIncidentContext();

        $event = $this->createPendingOutboxEvent(
            CashfreeWebhookDeferredOperationsService::OPERATION_AUTOMATION_MONITOR,
            $order,
            $incident,
            $actor,
        );

        $this->partialMock(ServiceCaseAutomationMonitorService::class, function ($mock): void {
            $mock->shouldReceive('recordPaymentReceived')
                ->once()
                ->andThrow(new RuntimeException('temporary failure'));
        });

        app(OutboxProcessorService::class)->process(1);

        $event->refresh();
        $this->assertSame(OutboxEventStatus::Pending, $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertTrue($event->available_at->isFuture());
        $this->assertSame('temporary failure', $event->last_error);
    }

    public function test_outbox_processing_is_idempotent_for_completed_events(): void
    {
        Queue::fake();

        ['order' => $order, 'incident' => $incident, 'actor' => $actor] = $this->createOrderIncidentContext();

        OutboxEvent::query()->create([
            'event_type' => CashfreeWebhookOutboxWriter::EVENT_TYPE,
            'aggregate_type' => CashfreeWebhookOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $incident->id,
            'payload' => [
                'operation' => CashfreeWebhookDeferredOperationsService::OPERATION_DASHBOARD_BROADCAST,
                'order_id' => $order->id,
                'incident_id' => $incident->id,
                'actor_id' => $actor->id,
            ],
            'status' => OutboxEventStatus::Completed,
            'attempts' => 1,
            'available_at' => now(),
            'processed_at' => now(),
            'idempotency_key' => 'cashfree.webhook.deferred.dashboard_broadcast.'.$incident->id,
        ]);

        $this->partialMock(DashboardBroadcastService::class, function ($mock): void {
            $mock->shouldReceive('serviceCaseCreated')->never();
        });

        $processed = app(OutboxProcessorService::class)->process();

        $this->assertSame(0, $processed);
    }

    public function test_outbox_processor_processes_events_in_fifo_order(): void
    {
        Queue::fake();

        ['order' => $order, 'incident' => $incident, 'actor' => $actor] = $this->createOrderIncidentContext();

        $operations = [
            CashfreeWebhookDeferredOperationsService::OPERATION_AUTOMATION_MONITOR,
            CashfreeWebhookDeferredOperationsService::OPERATION_DASHBOARD_BROADCAST,
            CashfreeWebhookDeferredOperationsService::OPERATION_RADIUMBOX_ENRICHMENT,
        ];

        foreach ($operations as $index => $operation) {
            $this->createPendingOutboxEvent($operation, $order, $incident, $actor, $index);
        }

        $processed = app(OutboxProcessorService::class)->process(3);

        $this->assertSame(3, $processed);

        $completedIds = OutboxEvent::query()
            ->where('status', OutboxEventStatus::Completed)
            ->orderBy('id')
            ->pluck('payload')
            ->map(fn (array $payload): string => $payload['operation'])
            ->all();

        $this->assertSame($operations, $completedIds);
    }

    public function test_crash_recovery_processes_stale_pending_events_later(): void
    {
        Queue::fake();
        $this->disableImmediateOutboxProcessing();

        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload('1453003004', 'order-crash-recovery'))
            ->assertOk();

        $this->assertSame(3, OutboxEvent::query()->where('status', OutboxEventStatus::Pending)->count());

        $this->restoreOutboxProcessor();
        $this->artisan('outbox:process')->assertSuccessful();

        $this->assertSame(3, OutboxEvent::query()->where('status', OutboxEventStatus::Completed)->count());
    }

    public function test_outbox_command_continues_after_individual_failures(): void
    {
        Queue::fake();

        ['order' => $order, 'incident' => $incident, 'actor' => $actor] = $this->createOrderIncidentContext();

        $this->createPendingOutboxEvent(
            CashfreeWebhookDeferredOperationsService::OPERATION_AUTOMATION_MONITOR,
            $order,
            $incident,
            $actor,
        );

        OutboxEvent::query()->create([
            'event_type' => CashfreeWebhookOutboxWriter::EVENT_TYPE,
            'aggregate_type' => CashfreeWebhookOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $incident->id + 1,
            'payload' => [
                'operation' => CashfreeWebhookDeferredOperationsService::OPERATION_DASHBOARD_BROADCAST,
                'order_id' => $order->id,
                'incident_id' => $incident->id,
                'actor_id' => $actor->id,
            ],
            'status' => OutboxEventStatus::Pending,
            'attempts' => 0,
            'available_at' => now(),
            'idempotency_key' => 'cashfree.webhook.deferred.dashboard_broadcast.'.$incident->id,
        ]);

        OutboxEvent::query()
            ->where('payload->operation', CashfreeWebhookDeferredOperationsService::OPERATION_AUTOMATION_MONITOR)
            ->update([
                'payload->actor_id' => 99999,
            ]);

        $this->artisan('outbox:process')->assertSuccessful();

        $this->assertSame(1, OutboxEvent::query()->where('status', OutboxEventStatus::Pending)->count());
        $this->assertSame(1, OutboxEvent::query()->where('status', OutboxEventStatus::Completed)->count());
    }
}
