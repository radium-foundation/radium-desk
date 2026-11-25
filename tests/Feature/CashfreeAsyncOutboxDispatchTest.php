<?php

namespace Tests\Feature;

use App\Enums\OutboxEventStatus;
use App\Infrastructure\Queue\QueueRouting;
use App\Jobs\Outbox\ProcessOutboxAggregateJob;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Services\Cashfree\CashfreeWebhookOutboxWriter;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use App\Services\Outbox\OutboxProcessorService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\EnsuresCashfreeSystemUser;
use Tests\TestCase;

class CashfreeAsyncOutboxDispatchTest extends TestCase
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
            ],
        ];
    }

    public function test_successful_webhook_enqueues_critical_aggregate_job_without_synchronous_drain(): void
    {
        Queue::fake();

        $this->partialMock(OutboxProcessorService::class, function ($mock): void {
            $mock->shouldReceive('process')->never();
            $mock->shouldReceive('processAggregate')->never();
        });

        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload('1453003001', 'order-async-1'))
            ->assertOk();

        $this->assertSame(
            CashfreeWebhookProcessorService::STATUS_PROCESSED,
            CashfreeWebhookLog::query()->latest('id')->value('processing_status'),
        );
        $this->assertSame(1, Order::query()->where('cashfree_payment_id', '1453003001')->count());

        $incident = Incident::query()->firstOrFail();

        $this->assertSame(
            3,
            OutboxEvent::query()
                ->where('aggregate_type', CashfreeWebhookOutboxWriter::AGGREGATE_TYPE)
                ->where('aggregate_id', $incident->id)
                ->where('status', OutboxEventStatus::Pending)
                ->count(),
        );

        Queue::assertPushed(ProcessOutboxAggregateJob::class, function (ProcessOutboxAggregateJob $job) use ($incident): bool {
            $this->assertSame(QueueRouting::critical(), $job->queue);
            $this->assertSame(CashfreeWebhookOutboxWriter::AGGREGATE_TYPE, $job->aggregateType);
            $this->assertSame($incident->id, $job->aggregateId);

            return true;
        });
    }

    public function test_scheduled_outbox_process_recovers_pending_cashfree_rows_when_queue_job_not_run(): void
    {
        Queue::fake();

        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload('1453003002', 'order-fallback'))
            ->assertOk();

        Queue::assertPushed(ProcessOutboxAggregateJob::class, 1);

        $incident = Incident::query()->firstOrFail();
        $this->assertSame(3, OutboxEvent::query()
            ->where('aggregate_id', $incident->id)
            ->where('status', OutboxEventStatus::Pending)
            ->count());

        $this->artisan('outbox:process', ['--limit' => 10])
            ->expectsOutputToContain('Processed 3 outbox event(s).')
            ->assertSuccessful();

        $this->assertSame(
            3,
            OutboxEvent::query()
                ->where('aggregate_id', $incident->id)
                ->where('status', OutboxEventStatus::Completed)
                ->count(),
        );
    }

    public function test_duplicate_webhook_enqueue_is_idempotent_for_payments_and_outbox(): void
    {
        Queue::fake();

        $payload = $this->successfulPayload('1453003003', 'order-dup-async');

        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();
        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Incident::query()->count());

        $incident = Incident::query()->firstOrFail();
        $this->assertSame(
            3,
            OutboxEvent::query()
                ->where('aggregate_id', $incident->id)
                ->count(),
        );

        Queue::assertPushed(ProcessOutboxAggregateJob::class, 1);
    }

    public function test_queue_dispatch_failure_does_not_roll_back_committed_payment_state(): void
    {
        $queue = Mockery::mock(\Illuminate\Contracts\Queue\Queue::class);
        $queue->shouldReceive('pushOn')->andReturnUsing(function (string $queueName, object $job): mixed {
            if ($job instanceof ProcessOutboxAggregateJob) {
                throw new \RuntimeException('queue unavailable');
            }

            return null;
        });
        $queue->shouldReceive('laterOn')->andReturnNull();
        $queue->shouldReceive('bulk')->andReturnNull();
        $queue->shouldReceive('size')->andReturn(0);
        $queue->shouldReceive('push')->andReturnNull();

        $factory = Mockery::mock(QueueManager::class);
        $factory->shouldReceive('connection')->andReturn($queue);
        $this->app->instance('queue', $factory);

        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload('1453003004', 'order-queue-fail'))
            ->assertOk();

        $this->assertSame(1, Order::query()->where('cashfree_payment_id', '1453003004')->count());
        $this->assertSame(
            CashfreeWebhookProcessorService::STATUS_PROCESSED,
            CashfreeWebhookLog::query()->latest('id')->value('processing_status'),
        );
        $this->assertSame(3, OutboxEvent::query()->where('status', OutboxEventStatus::Pending)->count());
    }
}
