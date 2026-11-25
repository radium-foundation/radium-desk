<?php

namespace Tests\Feature\Outbox;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OrderStatus;
use App\Enums\OutboxEventStatus;
use App\Jobs\Outbox\ProcessOutboxAggregateJob;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\EnsuresCashfreeSystemUser;
use Tests\TestCase;

class CashfreeOutboxQueueFallbackAndConcurrencyTest extends TestCase
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
            'radiumbox.enabled' => true,
            'cashfree.deferred_dashboard_broadcast_enabled' => true,
        ]);

        Cache::flush();
    }

    /**
     * @return array{order: Order, incident: Incident, actor: User}
     */
    private function createOrderIncidentContext(string $orderId): array
    {
        $actor = User::query()->where('email', 'superadmin@radium.local')->firstOrFail();

        $order = Order::query()->create([
            'order_id' => $orderId,
            'customer_name' => 'Jane Doe',
            'customer_email' => 'test@gmail.com',
            'customer_phone' => '9908734801',
            'cashfree_payment_id' => '1453011'.random_int(100, 999),
            'status' => OrderStatus::Active,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-FALLBACK-'.$order->id,
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Fallback test',
            'description' => 'P-25-22 fixture',
            'status' => IncidentStatus::AwaitingProductDetails,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        return compact('order', 'incident', 'actor');
    }

    private function seedCashfreeDeferredTriple(Order $order, Incident $incident, User $actor): void
    {
        foreach ([
            CashfreeWebhookDeferredOperationsService::OPERATION_AUTOMATION_MONITOR,
            CashfreeWebhookDeferredOperationsService::OPERATION_DASHBOARD_BROADCAST,
            CashfreeWebhookDeferredOperationsService::OPERATION_RADIUMBOX_ENRICHMENT,
        ] as $operation) {
            OutboxEvent::query()->create([
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
                'idempotency_key' => sprintf('cashfree.webhook.deferred.%s.%d.isolated', $operation, $incident->id),
            ]);
        }
    }

    public function test_scheduler_fallback_drains_pending_rows_after_queue_dispatch_failure(): void
    {
        Queue::fake();

        ['order' => $order, 'incident' => $incident, 'actor' => $actor] = $this->createOrderIncidentContext('order-fallback-p2522');
        $this->seedCashfreeDeferredTriple($order, $incident, $actor);

        $this->assertSame(3, OutboxEvent::query()
            ->where('aggregate_id', $incident->id)
            ->where('status', OutboxEventStatus::Pending)
            ->count());

        $this->artisan('outbox:process', ['--limit' => 10])
            ->expectsOutputToContain('Processed 3 outbox event(s).')
            ->assertSuccessful();

        $this->assertSame(3, OutboxEvent::query()
            ->where('aggregate_id', $incident->id)
            ->where('status', OutboxEventStatus::Completed)
            ->count());

        $this->artisan('outbox:process', ['--limit' => 10])
            ->expectsOutputToContain('Processed 0 outbox event(s).')
            ->assertSuccessful();
    }

    public function test_aggregate_job_overlapping_global_process_does_not_duplicate_dashboard(): void
    {
        Queue::fake();

        ['order' => $order, 'incident' => $incident, 'actor' => $actor] = $this->createOrderIncidentContext('order-overlap-p2522');
        $this->seedCashfreeDeferredTriple($order, $incident, $actor);

        $this->partialMock(ServiceCaseAutomationMonitorService::class, function ($mock): void {
            $mock->shouldReceive('recordPaymentReceived')->once();
        });

        $dashboardCalls = 0;

        $broadcast = Mockery::mock(DashboardBroadcastService::class);
        $broadcast->shouldReceive('serviceCaseCreated')
            ->once()
            ->andReturnUsing(function () use (&$dashboardCalls): void {
                $dashboardCalls++;
                app(OutboxProcessorService::class)->process(10);
            });
        $this->app->instance(DashboardBroadcastService::class, $broadcast);

        $job = new ProcessOutboxAggregateJob(
            CashfreeWebhookOutboxWriter::AGGREGATE_TYPE,
            $incident->id,
        );

        app()->call([$job, 'handle']);

        $this->assertSame(1, $dashboardCalls);

        $dashboard = OutboxEvent::query()
            ->where('aggregate_id', $incident->id)
            ->where('payload->operation', CashfreeWebhookDeferredOperationsService::OPERATION_DASHBOARD_BROADCAST)
            ->firstOrFail();

        $this->assertSame(OutboxEventStatus::Completed, $dashboard->status);
        $this->assertSame(1, $dashboard->attempts);
    }

    public function test_global_process_cannot_steal_rows_while_aggregate_processing_sibling_exists(): void
    {
        Queue::fake();

        ['order' => $order, 'incident' => $incident, 'actor' => $actor] = $this->createOrderIncidentContext('order-inflight-p2522');
        $this->seedCashfreeDeferredTriple($order, $incident, $actor);

        DB::transaction(function () use ($incident): void {
            $rows = OutboxEvent::query()
                ->where('aggregate_type', CashfreeWebhookOutboxWriter::AGGREGATE_TYPE)
                ->where('aggregate_id', $incident->id)
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                $row->update([
                    'status' => OutboxEventStatus::Processing,
                    'attempts' => 1,
                ]);
            }

            $stolen = app(OutboxProcessorService::class)->process(5);

            $this->assertSame(0, $stolen);
        });

        $this->assertSame(
            3,
            OutboxEvent::query()
                ->where('aggregate_id', $incident->id)
                ->where('status', OutboxEventStatus::Processing)
                ->count(),
        );
    }

    public function test_repeated_aggregate_job_execution_is_idempotent(): void
    {
        Queue::fake();

        $this->partialMock(ServiceCaseAutomationMonitorService::class, function ($mock): void {
            $mock->shouldReceive('recordPaymentReceived')->once();
        });

        $broadcast = Mockery::mock(DashboardBroadcastService::class);
        $broadcast->shouldReceive('serviceCaseCreated')->once();
        $this->app->instance(DashboardBroadcastService::class, $broadcast);

        ['order' => $order, 'incident' => $incident, 'actor' => $actor] = $this->createOrderIncidentContext('order-idempotent-p2522');
        $this->seedCashfreeDeferredTriple($order, $incident, $actor);

        $job = new ProcessOutboxAggregateJob(
            CashfreeWebhookOutboxWriter::AGGREGATE_TYPE,
            $incident->id,
        );

        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        $this->assertSame(3, OutboxEvent::query()
            ->where('aggregate_id', $incident->id)
            ->where('status', OutboxEventStatus::Completed)
            ->count());
    }
}
