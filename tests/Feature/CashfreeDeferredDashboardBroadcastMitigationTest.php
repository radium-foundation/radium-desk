<?php

namespace Tests\Feature;

use App\Data\CashfreeWebhookDeferredContext;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OrderStatus;
use App\Enums\OutboxEventStatus;
use App\Events\Dashboard\ServiceCaseCreated;
use App\Models\Incident;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\Cashfree\CashfreeWebhookDeferredOperationsService;
use App\Services\Cashfree\CashfreeWebhookOutboxWriter;
use App\Services\DashboardBroadcastService;
use App\Services\Outbox\OutboxProcessorService;
use App\Services\ServiceCaseAutomationMonitorService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Emergency mitigation: Cashfree deferred dashboard_broadcast is skipped while
 * automation_monitor + radiumbox_enrichment continue. Operator broadcasts unchanged.
 */
class CashfreeDeferredDashboardBroadcastMitigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'radiumbox.enabled' => true,
            'cashfree.deferred_dashboard_broadcast_enabled' => false,
        ]);
    }

    /**
     * @return array{order: Order, incident: Incident, actor: User}
     */
    private function createOrderIncidentContext(): array
    {
        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'ORD-MITIGATION-'.uniqid(),
            'customer_name' => 'Mitigation Customer',
            'customer_phone' => '9000000001',
            'status' => OrderStatus::Active,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'order_record_id' => $order->id,
            'reference_no' => 'SC-MIT-'.uniqid(),
            'category' => 'Support',
            'source' => IncidentSource::Cashfree,
            'title' => 'Mitigation case',
            'description' => 'Created for Cashfree deferred broadcast mitigation tests.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        return compact('order', 'incident', 'actor');
    }

    public function test_writer_skips_dashboard_broadcast_but_writes_monitor_and_enrichment(): void
    {
        ['order' => $order, 'incident' => $incident, 'actor' => $actor] = $this->createOrderIncidentContext();

        app(CashfreeWebhookOutboxWriter::class)->writeDeferredOperations(new CashfreeWebhookDeferredContext(
            orderId: $order->id,
            incidentId: $incident->id,
            actorId: $actor->id,
        ));

        $operations = OutboxEvent::query()
            ->where('event_type', CashfreeWebhookOutboxWriter::EVENT_TYPE)
            ->where('aggregate_id', $incident->id)
            ->orderBy('id')
            ->pluck('payload')
            ->map(fn (array $payload): string => $payload['operation'])
            ->all();

        $this->assertSame(
            [
                CashfreeWebhookDeferredOperationsService::OPERATION_AUTOMATION_MONITOR,
                CashfreeWebhookDeferredOperationsService::OPERATION_RADIUMBOX_ENRICHMENT,
            ],
            $operations,
        );
        $this->assertDatabaseMissing('outbox_events', [
            'idempotency_key' => 'cashfree.webhook.deferred.dashboard_broadcast.'.$incident->id,
        ]);
    }

    public function test_execute_skips_dashboard_broadcast_but_runs_monitor_and_enrichment(): void
    {
        Queue::fake();

        ['order' => $order, 'incident' => $incident, 'actor' => $actor] = $this->createOrderIncidentContext();

        $this->mock(ServiceCaseAutomationMonitorService::class, function ($mock) use ($incident, $actor): void {
            $mock->shouldReceive('recordPaymentReceived')
                ->once()
                ->withArgs(fn (Incident $i, User $u): bool => $i->is($incident) && $u->is($actor));
        });

        $this->mock(DashboardBroadcastService::class, function ($mock): void {
            $mock->shouldReceive('serviceCaseCreated')->never();
        });

        $service = app(CashfreeWebhookDeferredOperationsService::class);

        $service->executeOperation(
            CashfreeWebhookDeferredOperationsService::OPERATION_AUTOMATION_MONITOR,
            $order->id,
            $incident->id,
            $actor->id,
        );
        $service->executeOperation(
            CashfreeWebhookDeferredOperationsService::OPERATION_DASHBOARD_BROADCAST,
            $order->id,
            $incident->id,
            $actor->id,
        );
        $service->executeOperation(
            CashfreeWebhookDeferredOperationsService::OPERATION_RADIUMBOX_ENRICHMENT,
            $order->id,
            $incident->id,
            $actor->id,
        );

        Queue::assertPushed(\App\Jobs\RadiumBoxOrderEnrichmentJob::class);
    }

    public function test_leftover_pending_dashboard_broadcast_row_is_noop_completed(): void
    {
        Queue::fake();

        ['order' => $order, 'incident' => $incident, 'actor' => $actor] = $this->createOrderIncidentContext();

        $event = OutboxEvent::query()->create([
            'event_type' => CashfreeWebhookOutboxWriter::EVENT_TYPE,
            'aggregate_type' => CashfreeWebhookOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $incident->id,
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

        $this->mock(DashboardBroadcastService::class, function ($mock): void {
            $mock->shouldReceive('serviceCaseCreated')->never();
        });

        $processed = app(OutboxProcessorService::class)->process(1);

        $this->assertSame(1, $processed);
        $event->refresh();
        $this->assertSame(OutboxEventStatus::Completed, $event->status);
    }

    public function test_operator_dashboard_broadcast_still_fires_when_cashfree_deferred_broadcast_disabled(): void
    {
        Event::fake([ServiceCaseCreated::class]);

        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'ORD-OP-BROADCAST-'.uniqid(),
            'customer_name' => 'Operator Broadcast',
            'status' => OrderStatus::Active,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'order_record_id' => $order->id,
            'reference_no' => 'SC-OP-'.uniqid(),
            'category' => 'Support',
            'source' => IncidentSource::Internal,
            'title' => 'Operator case',
            'description' => 'Operator-created case for broadcast regression.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        app(DashboardBroadcastService::class)->serviceCaseCreated($incident, $actor);

        Event::assertDispatched(ServiceCaseCreated::class);
    }

    public function test_enabling_flag_restores_dashboard_broadcast_write_and_execute(): void
    {
        config(['cashfree.deferred_dashboard_broadcast_enabled' => true]);

        ['order' => $order, 'incident' => $incident, 'actor' => $actor] = $this->createOrderIncidentContext();

        app(CashfreeWebhookOutboxWriter::class)->writeDeferredOperations(new CashfreeWebhookDeferredContext(
            orderId: $order->id,
            incidentId: $incident->id,
            actorId: $actor->id,
        ));

        $this->assertDatabaseHas('outbox_events', [
            'idempotency_key' => 'cashfree.webhook.deferred.dashboard_broadcast.'.$incident->id,
        ]);

        $this->mock(DashboardBroadcastService::class, function ($mock) use ($incident, $actor): void {
            $mock->shouldReceive('serviceCaseCreated')
                ->once()
                ->withArgs(fn (Incident $i, User $u): bool => $i->is($incident) && $u->is($actor));
        });

        app(CashfreeWebhookDeferredOperationsService::class)->executeOperation(
            CashfreeWebhookDeferredOperationsService::OPERATION_DASHBOARD_BROADCAST,
            $order->id,
            $incident->id,
            $actor->id,
        );
    }
}
