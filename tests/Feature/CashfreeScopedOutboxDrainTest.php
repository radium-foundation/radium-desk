<?php

namespace Tests\Feature;

use App\Enums\OutboxEventStatus;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Services\Cashfree\CashfreeWebhookDeferredOperationsService;
use App\Services\Cashfree\CashfreeWebhookOutboxWriter;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use App\Services\DashboardBroadcastService;
use App\Services\Interakt\InteraktWebhookOutboxWriter;
use App\Services\Outbox\OutboxProcessorService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\EnsuresCashfreeSystemUser;
use Tests\TestCase;

/**
 * Cashfree deferred dispatch must drain only the payment's incident aggregate
 * (3 rows), never the global outbox FIFO.
 */
class CashfreeScopedOutboxDrainTest extends TestCase
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
            // Preserve full deferred triple for scoped-drain regression coverage.
            'cashfree.deferred_dashboard_broadcast_enabled' => true,
        ]);

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

    /**
     * @return list<OutboxEvent>
     */
    private function seedUnrelatedPendingOutbox(int $count = 20): array
    {
        $events = [];

        for ($i = 0; $i < $count; $i++) {
            $events[] = OutboxEvent::query()->create([
                'idempotency_key' => "unrelated.cashfree.deferred.{$i}",
                'event_type' => CashfreeWebhookOutboxWriter::EVENT_TYPE,
                'aggregate_type' => CashfreeWebhookOutboxWriter::AGGREGATE_TYPE,
                'aggregate_id' => 900000 + $i,
                'payload' => [
                    'operation' => CashfreeWebhookDeferredOperationsService::OPERATION_DASHBOARD_BROADCAST,
                    'order_id' => 900000 + $i,
                    'incident_id' => 900000 + $i,
                    'actor_id' => 1,
                ],
                'status' => OutboxEventStatus::Pending,
                'attempts' => 0,
                'available_at' => now()->subMinutes(5),
            ]);
        }

        $events[] = OutboxEvent::query()->create([
            'idempotency_key' => 'unrelated.interakt.webhook.1',
            'event_type' => InteraktWebhookOutboxWriter::EVENT_TYPE,
            'aggregate_type' => InteraktWebhookOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => 800001,
            'payload' => ['webhook_log_id' => 800001],
            'status' => OutboxEventStatus::Pending,
            'attempts' => 0,
            'available_at' => now()->subMinutes(5),
        ]);

        return $events;
    }

    public function test_cashfree_does_not_drain_unrelated_global_outbox_rows(): void
    {
        Queue::fake();

        $broadcast = Mockery::mock(DashboardBroadcastService::class);
        $broadcast->shouldReceive('serviceCaseCreated')->once();
        $this->app->instance(DashboardBroadcastService::class, $broadcast);

        $unrelated = $this->seedUnrelatedPendingOutbox(20);
        $unrelatedIds = collect($unrelated)->pluck('id')->all();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);

        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload('1453002901', 'order-scoped-drain'))
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);

        $elapsedMs = (hrtime(true) - $started) / 1e6;
        $queryCount = count(DB::getQueryLog());

        $log = CashfreeWebhookLog::query()->latest('id')->firstOrFail();
        $this->assertSame(CashfreeWebhookProcessorService::STATUS_PROCESSED, $log->processing_status);

        $order = Order::query()->where('cashfree_payment_id', '1453002901')->firstOrFail();
        $incident = Incident::query()->where('order_id', $order->id)->orWhere('order_record_id', $order->id)->first()
            ?? Incident::query()->findOrFail($log->incident_id);

        $this->assertNotNull($incident);

        $cashfreeDeferred = OutboxEvent::query()
            ->where('event_type', CashfreeWebhookOutboxWriter::EVENT_TYPE)
            ->where('aggregate_type', CashfreeWebhookOutboxWriter::AGGREGATE_TYPE)
            ->where('aggregate_id', $incident->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $cashfreeDeferred);
        $this->assertSame(
            [
                CashfreeWebhookDeferredOperationsService::OPERATION_AUTOMATION_MONITOR,
                CashfreeWebhookDeferredOperationsService::OPERATION_DASHBOARD_BROADCAST,
                CashfreeWebhookDeferredOperationsService::OPERATION_RADIUMBOX_ENRICHMENT,
            ],
            $cashfreeDeferred->pluck('payload.operation')->all(),
        );
        $this->assertTrue(
            $cashfreeDeferred->every(fn (OutboxEvent $event): bool => $event->status === OutboxEventStatus::Completed),
            'Expected all three Cashfree deferred rows to be Completed',
        );

        foreach ($unrelatedIds as $id) {
            $this->assertSame(
                OutboxEventStatus::Pending,
                OutboxEvent::query()->whereKey($id)->value('status'),
                "Unrelated outbox event {$id} must remain Pending",
            );
        }

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class);

        // With 21 unrelated pending rows, a global process() would have drained them.
        // Scoped drain should stay roughly comparable to an empty-outbox payment webhook.
        $this->assertLessThan(
            2500,
            $elapsedMs,
            "Expected scoped Cashfree webhook <2500ms with unrelated backlog, got {$elapsedMs}ms / {$queryCount} queries",
        );

        $this->assertSame(
            21,
            OutboxEvent::query()->whereIn('id', $unrelatedIds)->where('status', OutboxEventStatus::Pending)->count(),
        );
        $this->assertSame(
            0,
            OutboxEvent::query()->whereIn('id', $unrelatedIds)->where('status', OutboxEventStatus::Completed)->count(),
        );
    }

    public function test_cashfree_scoped_drain_wall_time_stable_with_unrelated_backlog(): void
    {
        Queue::fake();

        $emptyStarted = hrtime(true);
        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload('1453002902', 'order-bench-empty'))
            ->assertOk();
        $emptyMs = (hrtime(true) - $emptyStarted) / 1e6;

        $this->seedUnrelatedPendingOutbox(50);

        $backlogStarted = hrtime(true);
        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload('1453002903', 'order-bench-backlog'))
            ->assertOk();
        $backlogMs = (hrtime(true) - $backlogStarted) / 1e6;

        $completedForPayment = OutboxEvent::query()
            ->where('event_type', CashfreeWebhookOutboxWriter::EVENT_TYPE)
            ->where('status', OutboxEventStatus::Completed)
            ->count();

        // Two payments × 3 deferred ops = 6 completed Cashfree rows; unrelated stay Pending.
        $this->assertSame(6, $completedForPayment);
        $this->assertSame(
            51,
            OutboxEvent::query()->where('status', OutboxEventStatus::Pending)->count(),
        );

        // Backlog must not dominate wall time the way global process() would.
        $this->assertLessThan(
            max(500.0, $emptyMs * 4),
            $backlogMs,
            sprintf(
                'Backlog webhook should stay near empty-outbox cost (empty=%.1fms backlog=%.1fms)',
                $emptyMs,
                $backlogMs,
            ),
        );
    }

    public function test_cashfree_no_longer_calls_global_process_on_deferred_dispatch(): void
    {
        Queue::fake();

        $this->partialMock(OutboxProcessorService::class, function ($mock): void {
            $mock->shouldReceive('process')->never();
            $mock->shouldReceive('processAggregate')
                ->once()
                ->with(
                    CashfreeWebhookOutboxWriter::AGGREGATE_TYPE,
                    Mockery::type('int'),
                )
                ->andReturnNull();
        });

        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload('1453002904', 'order-no-global'))
            ->assertOk();

        $this->assertSame(
            CashfreeWebhookProcessorService::STATUS_PROCESSED,
            CashfreeWebhookLog::query()->latest('id')->value('processing_status'),
        );
        $this->assertSame(3, OutboxEvent::query()->where('status', OutboxEventStatus::Pending)->count());
    }
}
