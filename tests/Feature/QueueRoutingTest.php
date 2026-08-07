<?php

namespace Tests\Feature;

use App\Enums\QueueWorkerMode;
use App\Infrastructure\Queue\QueueRouting;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Jobs\SendServiceReferenceDriverGuideBatchJob;
use App\Jobs\SendServiceReferenceDriverGuideJob;
use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QueueRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_order_is_critical_then_notifications_then_default_then_maintenance(): void
    {
        $this->assertSame(
            'critical,notifications,default,maintenance',
            QueueRouting::workerOrder(),
        );
    }

    public function test_live_enrichment_dispatch_uses_critical_queue(): void
    {
        Queue::fake();

        $order = $this->createPaidOrder();

        app(RadiumBoxOrderEnrichmentService::class)->dispatch($order);

        Queue::assertPushedOn(QueueRouting::critical(), RadiumBoxOrderEnrichmentJob::class);
        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, function (RadiumBoxOrderEnrichmentJob $job) use ($order): bool {
            return $job->orderId === $order->id
                && $job->queue === QueueRouting::critical();
        });
    }

    public function test_maintenance_enrichment_dispatch_uses_maintenance_queue(): void
    {
        Queue::fake();

        $order = $this->createPaidOrder();

        app(RadiumBoxOrderEnrichmentService::class)->dispatchToMaintenance($order);

        Queue::assertPushedOn(QueueRouting::maintenance(), RadiumBoxOrderEnrichmentJob::class);
        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, function (RadiumBoxOrderEnrichmentJob $job) use ($order): bool {
            return $job->orderId === $order->id
                && $job->queue === QueueRouting::maintenance();
        });
    }

    public function test_retry_enrichment_uses_maintenance_queue(): void
    {
        Queue::fake();

        $order = $this->createPaidOrder();

        app(RadiumBoxOrderEnrichmentService::class)->retryOrderEnrichment($order);

        Queue::assertPushedOn(QueueRouting::maintenance(), RadiumBoxOrderEnrichmentJob::class);
    }

    public function test_driver_guide_job_uses_notifications_queue(): void
    {
        Queue::fake();

        SendServiceReferenceDriverGuideJob::dispatch(
            orderId: 19023,
            serviceReference: 'TXN-QUEUE-ROUTE-1',
            actorId: 2,
        );

        Queue::assertPushedOn(QueueRouting::notifications(), SendServiceReferenceDriverGuideJob::class);
        Queue::assertPushed(SendServiceReferenceDriverGuideJob::class, function (SendServiceReferenceDriverGuideJob $job): bool {
            return $job->orderId === 19023
                && $job->serviceReference === 'TXN-QUEUE-ROUTE-1'
                && $job->actorId === 2
                && $job->queue === QueueRouting::notifications();
        });
    }

    public function test_driver_guide_batch_job_uses_notifications_queue(): void
    {
        Queue::fake();

        SendServiceReferenceDriverGuideBatchJob::dispatch(
            items: [
                ['order_id' => 19023, 'service_reference' => 'TXN-QUEUE-BATCH-1'],
                ['order_id' => 19024, 'service_reference' => 'TXN-QUEUE-BATCH-1'],
            ],
            actorId: 2,
        );

        Queue::assertPushedOn(QueueRouting::notifications(), SendServiceReferenceDriverGuideBatchJob::class);
        Queue::assertPushed(SendServiceReferenceDriverGuideBatchJob::class, function (SendServiceReferenceDriverGuideBatchJob $job): bool {
            return $job->actorId === 2
                && count($job->items) === 2
                && $job->queue === QueueRouting::notifications();
        });
    }

    public function test_backfill_sync_command_routes_to_maintenance_queue(): void
    {
        Queue::fake();

        $order = $this->createPaidOrder();

        $this->artisan('radiumbox:backfill-sync', [
            '--order' => $order->order_id,
        ])->assertSuccessful();

        Queue::assertPushedOn(QueueRouting::maintenance(), RadiumBoxOrderEnrichmentJob::class);
    }

    public function test_scheduled_worker_command_matches_routing_order(): void
    {
        $this->assertSame(
            'queue:work database --queue=critical,notifications,default,maintenance --stop-when-empty --max-time=55 --tries=3 --sleep=1',
            QueueRouting::scheduledWorkerCommand(),
        );
    }

    public function test_dedicated_cron_mode_does_not_use_scheduler_worker(): void
    {
        config(['infrastructure.queue_worker_mode' => QueueWorkerMode::DedicatedCron->value]);
        $this->assertFalse(QueueWorkerMode::fromConfig()->runsViaScheduler());

        config(['infrastructure.queue_worker_mode' => QueueWorkerMode::Scheduler->value]);
        $this->assertTrue(QueueWorkerMode::fromConfig()->runsViaScheduler());
    }

    public function test_production_recover_queues_dry_run_shows_ordered_drain(): void
    {
        $this->artisan('production:recover-queues --dry-run --drain-queue --limit=5 --chunk=10 --skip-repairs --skip-radiumbox-backfill --skip-readyqueue-backfill --skip-automation-pending')
            ->expectsOutputToContain('queue:work database --queue=critical,notifications,default,maintenance')
            ->assertSuccessful();
    }

    private function createPaidOrder(): Order
    {
        $actor = User::factory()->create();

        return Order::query()->create([
            'order_id' => 'RD-QUEUE-'.uniqid(),
            'serial_number' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $actor->id,
            'cashfree_payment_id' => 'cf_'.uniqid(),
        ]);
    }
}
