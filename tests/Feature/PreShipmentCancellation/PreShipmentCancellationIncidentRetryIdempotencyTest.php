<?php

namespace Tests\Feature\PreShipmentCancellation;

use App\Enums\CommerceOrderStatus;
use App\Enums\IncidentStatus;
use App\Enums\PreShipmentCancellationServiceCaseSyncStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Infrastructure\Queue\QueueRouting;
use App\Jobs\PreShipmentCancellation\ClosePreShipmentCancellationIncidentJob;
use App\Models\AuditLog;
use App\Models\CommerceOrder;
use App\Models\Incident;
use App\Models\Order;
use App\Models\PreShipmentCancellationRequest;
use App\Models\User;
use App\Services\PreShipmentCancellation\PreShipmentCancellationFeature;
use App\Services\PreShipmentCancellation\PreShipmentCancellationIncidentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreShipmentCancellationIncidentRetryIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['pre_shipment_cancellation.enabled' => true]);
    }

    public function test_job_dispatches_to_critical_queue_consumed_by_worker_order(): void
    {
        $job = new ClosePreShipmentCancellationIncidentJob(1, 1);

        $this->assertSame(QueueRouting::critical(), $job->queue);
        $this->assertStringContainsString('critical', QueueRouting::workerOrder());
    }

    public function test_sequential_redelivery_does_not_duplicate_audit_notes(): void
    {
        [$actor, $case, $incident] = $this->failedSyncFixtures();

        $service = app(PreShipmentCancellationIncidentService::class);
        $service->retryClose($case, $actor);
        $afterFirst = AuditLog::query()
            ->where('event', PreShipmentCancellationIncidentService::AUDIT_HARDWARE_PRESHIP_CANCELLED)
            ->count();
        $this->assertSame(1, $afterFirst);

        $service->retryClose($case->fresh(), $actor);
        $job = new ClosePreShipmentCancellationIncidentJob($case->id, $actor->id);
        $job->handle(
            app(PreShipmentCancellationIncidentService::class),
            app(PreShipmentCancellationFeature::class),
        );

        $this->assertSame(
            $afterFirst,
            AuditLog::query()
                ->where('event', PreShipmentCancellationIncidentService::AUDIT_HARDWARE_PRESHIP_CANCELLED)
                ->count(),
        );
        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
        $this->assertSame(
            PreShipmentCancellationServiceCaseSyncStatus::Succeeded,
            $case->fresh()->service_case_sync_status,
        );
    }

    public function test_already_synced_case_short_circuits_without_new_audit(): void
    {
        [$actor, $case] = $this->failedSyncFixtures();
        $case->forceFill([
            'service_case_sync_status' => PreShipmentCancellationServiceCaseSyncStatus::Succeeded,
        ])->save();

        $before = AuditLog::query()->count();
        app(PreShipmentCancellationIncidentService::class)->retryClose($case, $actor);

        $this->assertSame($before, AuditLog::query()->count());
    }

    /**
     * @return array{0: User, 1: PreShipmentCancellationRequest, 2: Incident}
     */
    private function failedSyncFixtures(): array
    {
        $actor = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RBP9200200',
            'product_name' => 'Device',
            'device_model' => 'M1',
            'status' => 'active',
            'payment_amount' => 1000,
            'cashfree_payment_id' => 'cf_idem',
            'created_by' => $actor->id,
        ]);

        CommerceOrder::query()->create([
            'order_no' => 'CO-IDEM',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $order->order_id,
            'idempotency_key' => 'psc-idem',
            'payload_hash' => 'hash',
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'order_value' => 1000,
            'received_at' => now(),
            'support_order_id' => $order->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-IDEM',
            'category' => 'Hardware',
            'source' => 'internal',
            'title' => 'Hardware',
            'description' => 'Test',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);

        $case = PreShipmentCancellationRequest::query()->create([
            'reference_no' => 'PSC-IDEM-0001',
            'order_id' => $order->id,
            'source_id' => $order->order_id,
            'order_family' => 'rbp',
            'status' => 'held_service_case',
            'cancellation_reason' => 'Idempotency test',
            'submitted_by_user_id' => $actor->id,
            'order_total_amount' => 1000,
            'wallet_tender_amount' => 0,
            'external_tender_amount' => 1000,
            'already_refunded_amount' => 0,
            'maximum_refundable_amount' => 1000,
            'requested_refund_amount' => 1000,
            'split_tender' => false,
            'execution_hold' => false,
            'idempotency_key' => 'psc-idem-case',
            'service_case_sync_status' => PreShipmentCancellationServiceCaseSyncStatus::Failed,
            'committed_at' => now(),
        ]);

        return [$actor, $case, $incident];
    }
}
