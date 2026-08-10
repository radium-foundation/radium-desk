<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OrderStatus;
use App\Enums\OutboxEventStatus;
use App\Models\Incident;
use App\Models\InteraktWebhookLog;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\Cashfree\CashfreeWebhookDeferredOperationsService;
use App\Services\Cashfree\CashfreeWebhookOutboxWriter;
use App\Services\DashboardBroadcastService;
use App\Services\IncomingEmail\IncomingEmailOutboxWriter;
use App\Services\Interakt\InteraktWebhookOutboxWriter;
use App\Services\Interakt\InteraktWebhookProcessorService;
use App\Services\Outbox\OutboxProcessorService;
use App\Services\ServiceCaseAutomationMonitorService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\Concerns\EnsuresCashfreeSystemUser;
use Tests\TestCase;

/**
 * Phase A: Cashfree aggregate claim-all + global claim guard.
 *
 * Global outbox:process must not steal Cashfree deferred siblings while a
 * scoped processAggregate drain owns the incident aggregate.
 */
class OutboxCashfreeAggregateClaimGuardTest extends TestCase
{
    use EnsuresCashfreeSystemUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->ensureCashfreeSystemUser();
        $this->seed(SettingsSeeder::class);
        config([
            'radiumbox.enabled' => false,
            // Claim-guard tests assert scoped drain of the full deferred triple.
            'cashfree.deferred_dashboard_broadcast_enabled' => true,
        ]);

        Cache::flush();
    }

    /**
     * @return array{order: Order, incident: Incident, actor: User}
     */
    private function createOrderIncidentContext(string $orderId = 'order-claim-guard'): array
    {
        $actor = User::query()->where('email', 'superadmin@radium.local')->firstOrFail();

        $order = Order::query()->create([
            'order_id' => $orderId,
            'customer_name' => 'Jane Doe',
            'customer_email' => 'test@gmail.com',
            'customer_phone' => '9908734801',
            'cashfree_payment_id' => '1453004'.random_int(100, 999),
            'status' => OrderStatus::Active,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-CLAIM-GUARD-'.$order->id,
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Claim guard test',
            'description' => 'Phase A concurrency fixture.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        return compact('order', 'incident', 'actor');
    }

    /**
     * @return list<OutboxEvent>
     */
    private function seedCashfreeDeferredTriple(Order $order, Incident $incident, User $actor): array
    {
        $events = [];

        foreach ([
            CashfreeWebhookDeferredOperationsService::OPERATION_AUTOMATION_MONITOR,
            CashfreeWebhookDeferredOperationsService::OPERATION_DASHBOARD_BROADCAST,
            CashfreeWebhookDeferredOperationsService::OPERATION_RADIUMBOX_ENRICHMENT,
        ] as $operation) {
            $events[] = OutboxEvent::query()->create([
                'event_type' => CashfreeWebhookOutboxWriter::EVENT_TYPE,
                'aggregate_type' => CashfreeWebhookOutboxWriter::AGGREGATE_TYPE,
                'aggregate_id' => $incident->id,
                'payload' => [
                    'operation' => $operation,
                    'order_id' => $order->id,
                    'incident_id' => $incident->id,
                    'actor_id' => $actor->id,
                ],
                'status' => OutboxEventStatus::Pending,
                'attempts' => 0,
                'available_at' => now()->subMinute(),
                'idempotency_key' => sprintf('cashfree.webhook.deferred.%s.%d', $operation, $incident->id),
            ]);
        }

        return $events;
    }

    private function seedPendingInterakt(): OutboxEvent
    {
        $log = InteraktWebhookLog::query()->create([
            'event_type' => 'message_received',
            'payload' => ['test' => true],
            'processing_status' => InteraktWebhookLog::STATUS_RECEIVED,
            'received_at' => now(),
        ]);

        return OutboxEvent::query()->create([
            'event_type' => InteraktWebhookOutboxWriter::EVENT_TYPE,
            'aggregate_type' => InteraktWebhookOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $log->id,
            'payload' => ['webhook_log_id' => $log->id],
            'status' => OutboxEventStatus::Pending,
            'attempts' => 0,
            'available_at' => now()->subMinutes(5),
            'idempotency_key' => 'interakt.webhook.process.'.$log->id,
        ]);
    }

    public function test_scoped_drain_claims_all_cashfree_rows_before_dispatch(): void
    {
        Queue::fake();

        ['order' => $order, 'incident' => $incident, 'actor' => $actor] = $this->createOrderIncidentContext();
        $this->seedCashfreeDeferredTriple($order, $incident, $actor);

        $seenStatuses = null;

        $broadcast = Mockery::mock(DashboardBroadcastService::class);
        $broadcast->shouldReceive('serviceCaseCreated')
            ->once()
            ->andReturnUsing(function () use ($incident, &$seenStatuses): void {
                $seenStatuses = OutboxEvent::query()
                    ->where('aggregate_type', CashfreeWebhookOutboxWriter::AGGREGATE_TYPE)
                    ->where('aggregate_id', $incident->id)
                    ->orderBy('id')
                    ->get(['status', 'payload'])
                    ->map(fn (OutboxEvent $event): array => [
                        'operation' => $event->payload['operation'] ?? null,
                        'status' => $event->status,
                    ])
                    ->all();
            });
        $this->app->instance(DashboardBroadcastService::class, $broadcast);

        app(OutboxProcessorService::class)->processAggregate(
            CashfreeWebhookOutboxWriter::AGGREGATE_TYPE,
            $incident->id,
        );

        $this->assertNotNull($seenStatuses);
        $this->assertCount(3, $seenStatuses);

        // During dashboard_broadcast, claim-all already marked every sibling Processing
        // (monitor may already be Completed if processed first).
        foreach ($seenStatuses as $row) {
            $this->assertContains(
                $row['status'],
                [OutboxEventStatus::Processing, OutboxEventStatus::Completed],
                'Mid-drain sibling must not remain Pending for cron to steal',
            );
        }

        $this->assertSame(
            3,
            OutboxEvent::query()
                ->where('aggregate_id', $incident->id)
                ->where('status', OutboxEventStatus::Completed)
                ->count(),
        );
    }

    public function test_global_process_cannot_steal_cashfree_rows_during_scoped_drain(): void
    {
        Queue::fake();

        ['order' => $order, 'incident' => $incident, 'actor' => $actor] = $this->createOrderIncidentContext('order-no-steal');
        $cashfree = $this->seedCashfreeDeferredTriple($order, $incident, $actor);
        $interakt = $this->seedPendingInterakt();

        $nestedGlobalProcessed = null;
        $dashboardCalls = 0;

        $this->mock(InteraktWebhookProcessorService::class, function ($mock): void {
            $mock->shouldReceive('process')->once();
        });

        $broadcast = Mockery::mock(DashboardBroadcastService::class);
        $broadcast->shouldReceive('serviceCaseCreated')
            ->once()
            ->andReturnUsing(function () use (&$nestedGlobalProcessed, &$dashboardCalls): void {
                $dashboardCalls++;
                $nestedGlobalProcessed = app(OutboxProcessorService::class)->process(20);
            });
        $this->app->instance(DashboardBroadcastService::class, $broadcast);

        app(OutboxProcessorService::class)->processAggregate(
            CashfreeWebhookOutboxWriter::AGGREGATE_TYPE,
            $incident->id,
        );

        $this->assertSame(1, $dashboardCalls);
        $this->assertSame(1, $nestedGlobalProcessed, 'Nested global process should only claim Interakt');

        foreach ($cashfree as $event) {
            $event->refresh();
            $this->assertSame(OutboxEventStatus::Completed, $event->status);
            $this->assertSame(1, $event->attempts, 'Cashfree rows must not be double-claimed');
        }

        $interakt->refresh();
        $this->assertSame(OutboxEventStatus::Completed, $interakt->status);
    }

    public function test_unrelated_interakt_and_email_remain_processable_while_cashfree_aggregate_processing(): void
    {
        Queue::fake();

        ['order' => $order, 'incident' => $incident, 'actor' => $actor] = $this->createOrderIncidentContext('order-unrelated');
        $cashfree = $this->seedCashfreeDeferredTriple($order, $incident, $actor);

        // Simulate in-flight claim-all (all CF siblings Processing).
        OutboxEvent::query()
            ->whereIn('id', collect($cashfree)->pluck('id'))
            ->update([
                'status' => OutboxEventStatus::Processing,
                'attempts' => 1,
                'updated_at' => now(),
            ]);

        $interakt = $this->seedPendingInterakt();

        $email = OutboxEvent::query()->create([
            'event_type' => IncomingEmailOutboxWriter::EVENT_TYPE,
            'aggregate_type' => IncomingEmailOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => 700001,
            'payload' => ['incoming_email_message_id' => 700001],
            'status' => OutboxEventStatus::Pending,
            'attempts' => 0,
            'available_at' => now()->subMinute(),
            'idempotency_key' => 'email.inbound.process.700001',
        ]);

        $this->mock(InteraktWebhookProcessorService::class, function ($mock): void {
            $mock->shouldReceive('process')->once();
        });

        // Email message is missing → claim + fail + backoff Pending. That still
        // proves the aggregate guard did not block unrelated FIFO rows.
        $processed = app(OutboxProcessorService::class)->process(10);

        $this->assertSame(2, $processed);

        foreach ($cashfree as $event) {
            $event->refresh();
            $this->assertSame(
                OutboxEventStatus::Processing,
                $event->status,
                'In-flight Cashfree rows must not be claimed by global process',
            );
            $this->assertSame(1, $event->attempts);
        }

        $interakt->refresh();
        $this->assertSame(OutboxEventStatus::Completed, $interakt->status);
        $this->assertSame(1, $interakt->attempts);

        $email->refresh();
        $this->assertSame(OutboxEventStatus::Pending, $email->status);
        $this->assertSame(1, $email->attempts);
        $this->assertTrue($email->available_at->isFuture());
    }

    public function test_failed_scoped_rows_remain_recoverable_by_global_process(): void
    {
        Queue::fake();

        ['order' => $order, 'incident' => $incident, 'actor' => $actor] = $this->createOrderIncidentContext('order-recover');
        $this->seedCashfreeDeferredTriple($order, $incident, $actor);

        $this->partialMock(ServiceCaseAutomationMonitorService::class, function ($mock): void {
            $mock->shouldReceive('recordPaymentReceived')
                ->once()
                ->andThrow(new RuntimeException('scoped monitor failed'));
        });

        $broadcast = Mockery::mock(DashboardBroadcastService::class);
        $broadcast->shouldReceive('serviceCaseCreated')->once();
        $this->app->instance(DashboardBroadcastService::class, $broadcast);

        app(OutboxProcessorService::class)->processAggregate(
            CashfreeWebhookOutboxWriter::AGGREGATE_TYPE,
            $incident->id,
        );

        $monitor = OutboxEvent::query()
            ->where('aggregate_id', $incident->id)
            ->where('payload->operation', CashfreeWebhookDeferredOperationsService::OPERATION_AUTOMATION_MONITOR)
            ->firstOrFail();

        $this->assertSame(OutboxEventStatus::Pending, $monitor->status);
        $this->assertTrue($monitor->available_at->isFuture());

        $this->assertSame(
            2,
            OutboxEvent::query()
                ->where('aggregate_id', $incident->id)
                ->where('status', OutboxEventStatus::Completed)
                ->count(),
        );

        // After backoff, global cron must recover the leftover (no orphan).
        $monitor->update(['available_at' => now()->subSecond()]);

        $this->partialMock(ServiceCaseAutomationMonitorService::class, function ($mock): void {
            $mock->shouldReceive('recordPaymentReceived')->once();
        });

        $processed = app(OutboxProcessorService::class)->process(5);

        $this->assertSame(1, $processed);
        $this->assertSame(
            3,
            OutboxEvent::query()
                ->where('aggregate_id', $incident->id)
                ->where('status', OutboxEventStatus::Completed)
                ->count(),
        );
    }

    public function test_concurrent_process_vs_process_aggregate_does_not_duplicate_dashboard_dispatch(): void
    {
        Queue::fake();

        ['order' => $order, 'incident' => $incident, 'actor' => $actor] = $this->createOrderIncidentContext('order-no-dup');
        $this->seedCashfreeDeferredTriple($order, $incident, $actor);

        $dashboardCalls = 0;

        $broadcast = Mockery::mock(DashboardBroadcastService::class);
        $broadcast->shouldReceive('serviceCaseCreated')
            ->once()
            ->andReturnUsing(function () use (&$dashboardCalls): void {
                $dashboardCalls++;
                // Attempt steal during the expensive handler.
                app(OutboxProcessorService::class)->process(10);
            });
        $this->app->instance(DashboardBroadcastService::class, $broadcast);

        app(OutboxProcessorService::class)->processAggregate(
            CashfreeWebhookOutboxWriter::AGGREGATE_TYPE,
            $incident->id,
        );

        $this->assertSame(1, $dashboardCalls);

        $dashboard = OutboxEvent::query()
            ->where('aggregate_id', $incident->id)
            ->where('payload->operation', CashfreeWebhookDeferredOperationsService::OPERATION_DASHBOARD_BROADCAST)
            ->firstOrFail();

        $this->assertSame(OutboxEventStatus::Completed, $dashboard->status);
        $this->assertSame(1, $dashboard->attempts);
    }

    public function test_global_process_still_drains_cashfree_leftovers_when_no_inflight_sibling(): void
    {
        Queue::fake();

        ['order' => $order, 'incident' => $incident, 'actor' => $actor] = $this->createOrderIncidentContext('order-fallback');
        $this->seedCashfreeDeferredTriple($order, $incident, $actor);

        $broadcast = Mockery::mock(DashboardBroadcastService::class);
        $broadcast->shouldReceive('serviceCaseCreated')->once();
        $this->app->instance(DashboardBroadcastService::class, $broadcast);

        $processed = app(OutboxProcessorService::class)->process(10);

        $this->assertSame(3, $processed);
        $this->assertSame(
            3,
            OutboxEvent::query()
                ->where('aggregate_id', $incident->id)
                ->where('status', OutboxEventStatus::Completed)
                ->count(),
        );
    }

    public function test_fifo_order_preserved_across_unrelated_pending_when_no_guard_blocks(): void
    {
        Queue::fake();

        ['order' => $order, 'incident' => $incident, 'actor' => $actor] = $this->createOrderIncidentContext('order-fifo');

        $first = OutboxEvent::query()->create([
            'event_type' => CashfreeWebhookOutboxWriter::EVENT_TYPE,
            'aggregate_type' => CashfreeWebhookOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $incident->id,
            'payload' => [
                'operation' => CashfreeWebhookDeferredOperationsService::OPERATION_AUTOMATION_MONITOR,
                'order_id' => $order->id,
                'incident_id' => $incident->id,
                'actor_id' => $actor->id,
            ],
            'status' => OutboxEventStatus::Pending,
            'attempts' => 0,
            'available_at' => now()->subMinutes(2),
            'idempotency_key' => 'cashfree.webhook.deferred.automation_monitor.'.$incident->id,
        ]);

        $secondIncidentId = $incident->id + 50;
        $second = OutboxEvent::query()->create([
            'event_type' => CashfreeWebhookOutboxWriter::EVENT_TYPE,
            'aggregate_type' => CashfreeWebhookOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $secondIncidentId,
            'payload' => [
                'operation' => CashfreeWebhookDeferredOperationsService::OPERATION_AUTOMATION_MONITOR,
                'order_id' => $order->id,
                'incident_id' => $incident->id,
                'actor_id' => $actor->id,
            ],
            'status' => OutboxEventStatus::Pending,
            'attempts' => 0,
            'available_at' => now()->subMinute(),
            'idempotency_key' => 'cashfree.webhook.deferred.automation_monitor.'.$secondIncidentId,
        ]);

        $processed = app(OutboxProcessorService::class)->process(1);
        $this->assertSame(1, $processed);

        $first->refresh();
        $second->refresh();

        $this->assertSame(OutboxEventStatus::Completed, $first->status);
        $this->assertSame(OutboxEventStatus::Pending, $second->status);
    }
}
