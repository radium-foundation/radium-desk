<?php

namespace Tests\Feature\PreShipmentCancellation;

use App\Enums\CommerceOrderStatus;
use App\Enums\IncidentStatus;
use App\Enums\PreShipmentCancellationServiceCaseSyncStatus;
use App\Enums\StatutoryInvoiceChannel;
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

class PreShipmentCancellationCloseIncidentJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['pre_shipment_cancellation.enabled' => true]);
    }

    public function test_job_retries_close_and_is_idempotent_without_duplicate_audit(): void
    {
        $actor = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RBP9200001',
            'product_name' => 'Device',
            'device_model' => 'M1',
            'status' => 'active',
            'payment_amount' => 1000,
            'cashfree_payment_id' => 'cf_job',
            'created_by' => $actor->id,
        ]);

        CommerceOrder::query()->create([
            'order_no' => 'CO-JOB',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $order->order_id,
            'idempotency_key' => 'psc-job',
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
            'reference_no' => 'INC-JOB',
            'category' => 'Hardware',
            'source' => 'internal',
            'title' => 'Hardware',
            'description' => 'Test',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);

        $case = PreShipmentCancellationRequest::query()->create([
            'reference_no' => 'PSC-TEST-0001',
            'order_id' => $order->id,
            'source_id' => $order->order_id,
            'order_family' => 'rbp',
            'status' => 'held_service_case',
            'cancellation_reason' => 'Job test',
            'submitted_by_user_id' => $actor->id,
            'order_total_amount' => 1000,
            'wallet_tender_amount' => 0,
            'external_tender_amount' => 1000,
            'already_refunded_amount' => 0,
            'maximum_refundable_amount' => 1000,
            'requested_refund_amount' => 1000,
            'split_tender' => false,
            'execution_hold' => false,
            'idempotency_key' => 'psc-job-case',
            'service_case_sync_status' => PreShipmentCancellationServiceCaseSyncStatus::Failed,
            'committed_at' => now(),
        ]);

        $job = new ClosePreShipmentCancellationIncidentJob($case->id, $actor->id);
        $this->assertSame('psc-incident:'.$case->id, $job->uniqueId());
        $this->assertSame(4, $job->tries);

        $job->handle(
            app(PreShipmentCancellationIncidentService::class),
            app(PreShipmentCancellationFeature::class),
        );

        $auditCountAfterFirst = AuditLog::query()
            ->where('event', PreShipmentCancellationIncidentService::AUDIT_HARDWARE_PRESHIP_CANCELLED)
            ->count();

        $job->handle(
            app(PreShipmentCancellationIncidentService::class),
            app(PreShipmentCancellationFeature::class),
        );

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
        $this->assertSame(
            PreShipmentCancellationServiceCaseSyncStatus::Succeeded,
            $case->fresh()->service_case_sync_status,
        );
        $this->assertSame(
            $auditCountAfterFirst,
            AuditLog::query()
                ->where('event', PreShipmentCancellationIncidentService::AUDIT_HARDWARE_PRESHIP_CANCELLED)
                ->count(),
        );
    }

    public function test_job_skips_when_feature_disabled(): void
    {
        config(['pre_shipment_cancellation.enabled' => false]);
        $job = new ClosePreShipmentCancellationIncidentJob(999, 1);
        $job->handle(
            app(PreShipmentCancellationIncidentService::class),
            app(PreShipmentCancellationFeature::class),
        );
        $this->assertTrue(true);
    }
}
