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
use App\Models\User;
use App\Services\PreShipmentCancellation\PreShipmentCancellationFeature;
use App\Services\PreShipmentCancellation\PreShipmentCancellationIncidentService;
use App\Services\PreShipmentCancellation\PreShipmentCancellationSubmitService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PreShipmentCancellationFeatureFlagRollbackSequenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        foreach ([
            'preship_cancellations.submit',
            'preship_cancellations.view',
            'preship_cancellations.review',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        config(['pre_shipment_cancellation.enabled' => true]);
    }

    public function test_incident_close_job_respects_feature_flag_off_on_then_retry_and_replay(): void
    {
        [$actor, $order, $commerce] = $this->paidHardwareWithDuplicateOpenIncidents();

        Bus::fake([ClosePreShipmentCancellationIncidentJob::class]);

        $case = app(PreShipmentCancellationSubmitService::class)->submit($actor, $order, [
            'cancellation_reason' => 'Rollback sequence synthetic.',
            'idempotency_key' => 'psc-rollback-'.$order->id,
        ]);

        $this->assertSame(
            PreShipmentCancellationServiceCaseSyncStatus::Failed,
            $case->fresh()->service_case_sync_status,
        );
        $blockedAt = $commerce->fresh()->hardware_fulfilment_blocked_at;
        $this->assertNotNull($blockedAt);

        /** @var ClosePreShipmentCancellationIncidentJob|null $queuedJob */
        $queuedJob = null;
        Bus::assertDispatched(ClosePreShipmentCancellationIncidentJob::class, function (ClosePreShipmentCancellationIncidentJob $job) use ($case, &$queuedJob): bool {
            if ($job->preShipmentCancellationRequestId !== $case->id) {
                return false;
            }
            $queuedJob = $job;

            return true;
        });
        $this->assertNotNull($queuedJob);

        config(['pre_shipment_cancellation.enabled' => false]);
        $queuedJob->handle(
            app(PreShipmentCancellationIncidentService::class),
            app(PreShipmentCancellationFeature::class),
        );

        $this->assertSame(
            PreShipmentCancellationServiceCaseSyncStatus::Failed,
            $case->fresh()->service_case_sync_status,
        );
        $this->assertTrue(
            $commerce->fresh()->hardware_fulfilment_blocked_at?->equalTo($blockedAt) ?? false,
        );

        $this->assertSame(2, Incident::query()->where('order_id', $order->id)->where('status', IncidentStatus::Open)->count());
        Incident::query()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->limit(1)
            ->delete();
        $this->assertSame(1, Incident::query()->where('order_id', $order->id)->where('status', IncidentStatus::Open)->count());

        config(['pre_shipment_cancellation.enabled' => true]);

        Bus::swap($this->app->make(Dispatcher::class));

        $this->actingAs($actor)
            ->post(route('pre-shipment-cancellations.retry-incident', $case))
            ->assertRedirect();

        $incident = Incident::query()->where('order_id', $order->id)->firstOrFail();
        $retryJob = new ClosePreShipmentCancellationIncidentJob($case->fresh()->id, $actor->id);
        $retryJob->handle(
            app(PreShipmentCancellationIncidentService::class),
            app(PreShipmentCancellationFeature::class),
        );
        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
        $this->assertSame(
            PreShipmentCancellationServiceCaseSyncStatus::Succeeded,
            $case->fresh()->service_case_sync_status,
        );

        $auditCount = AuditLog::query()
            ->where('event', PreShipmentCancellationIncidentService::AUDIT_HARDWARE_PRESHIP_CANCELLED)
            ->count();
        $this->assertSame(1, $auditCount);

        $retryJob = new ClosePreShipmentCancellationIncidentJob($case->id, $actor->id);
        $retryJob->handle(
            app(PreShipmentCancellationIncidentService::class),
            app(PreShipmentCancellationFeature::class),
        );

        $this->assertSame(
            $auditCount,
            AuditLog::query()
                ->where('event', PreShipmentCancellationIncidentService::AUDIT_HARDWARE_PRESHIP_CANCELLED)
                ->count(),
        );
        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
    }

    /**
     * @return array{0: User, 1: Order, 2: CommerceOrder}
     */
    private function paidHardwareWithDuplicateOpenIncidents(): array
    {
        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);
        $actor->givePermissionTo([
            'preship_cancellations.submit',
            'preship_cancellations.view',
            'preship_cancellations.review',
        ]);

        $orderId = 'RBP9100991';
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => null,
            'product_name' => 'Rollback Device',
            'device_model' => 'M1',
            'status' => 'active',
            'payment_amount' => 2000,
            'cashfree_payment_id' => 'cf_'.$orderId,
            'created_by' => $actor->id,
        ]);

        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$orderId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $orderId,
            'idempotency_key' => 'psc-rollback-'.$order->id,
            'payload_hash' => hash('sha256', $orderId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'order_value' => 2000,
            'received_at' => now(),
            'support_order_id' => $order->id,
        ]);

        foreach (['INC-RB-A', 'INC-RB-B'] as $ref) {
            Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => $ref,
                'category' => 'Hardware',
                'source' => 'internal',
                'title' => 'Hardware',
                'description' => 'Awaiting serial',
                'status' => IncidentStatus::Open,
                'created_by' => $actor->id,
            ]);
        }

        return [$actor, $order, $commerce];
    }
}
