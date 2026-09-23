<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Data\Automation\PlannedAutomationAction;
use App\Enums\AutomationPolicyActionType;
use App\Enums\CommerceOrderStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OutboxEventStatus;
use App\Enums\StatutoryInvoice\ServiceStatutoryInvoiceMintTrigger;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\WaitingReason;
use App\Models\CommerceOrder;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\InvoiceSequenceAllocation;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Automation\CustomerWaitingLifecycleService;
use App\Services\IncidentReferenceService;
use App\Services\OrderTransactionService;
use App\Services\Outbox\OutboxProcessorService;
use App\Services\StatutoryInvoice\ServiceStatutoryInvoiceIssuanceCoordinator;
use App\Services\StatutoryInvoice\ServiceStatutoryInvoiceIssuer;
use App\Services\StatutoryInvoice\ServiceStatutoryInvoiceMintOutboxWriter;
use App\Services\StatutoryInvoice\ServiceStatutoryInvoiceMintProcessor;
use App\Services\StatutoryInvoice\ServiceStatutoryInvoiceReconciliationService;
use App\Services\StatutoryInvoice\ServiceStatutoryInvoiceTemporaryFailureException;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class ServiceStatutoryInvoiceMintRetryTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-06 18:00:00');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->configureLocationSellerIdentity();
        config([
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'channel_ingest.auto_issue_invoice' => false,
            'service_statutory_invoice.mint_retry.enabled' => true,
            'service_statutory_invoice.reconciliation.enabled' => true,
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        $this->actor = User::factory()->create(['is_active' => true, 'email' => 'superadmin@radium.local']);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_reference_assignment_still_issues_immediately_on_success(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD3512501');

        app(OrderTransactionService::class)->assignTransactionId(
            $order,
            'TXN-RETRY-OK',
            $this->actor,
            broadcast: false,
        );

        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(0, OutboxEvent::query()
            ->where('event_type', ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE)
            ->where('status', '!=', OutboxEventStatus::Completed)
            ->count());
    }

    public function test_missing_commerce_records_permanent_failure_outbox_without_invoice(): void
    {
        $order = $this->deskOrder('RD3512502');

        app(OrderTransactionService::class)->assignTransactionId(
            $order,
            'TXN-NO-COMMERCE',
            $this->actor,
            broadcast: false,
        );

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $outbox = OutboxEvent::query()
            ->where('event_type', ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE)
            ->first();
        $this->assertNotNull($outbox);
        $this->assertSame(OutboxEventStatus::Failed, $outbox->status);
        $this->assertNotNull($outbox->last_error);
    }

    public function test_transient_failure_enqueues_retry_outbox_and_recovers_on_process(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD3512503');

        $attempts = 0;
        $invoiceService = Mockery::mock(StatutoryInvoiceService::class);
        $invoiceService->shouldReceive('issueFromSupportOrder')
            ->andReturnUsing(function () use (&$attempts, $order) {
                $attempts++;
                if ($attempts === 1) {
                    throw new ServiceStatutoryInvoiceTemporaryFailureException('connection reset');
                }

                return app(StatutoryInvoiceService::class)->issueFromSupportOrder($order->fresh(), $this->actor);
            });
        $invoiceService->shouldReceive('findBySource')->andReturn(null);
        $this->app->instance(StatutoryInvoiceService::class, $invoiceService);
        $this->app->forgetInstance(ServiceStatutoryInvoiceIssuanceCoordinator::class);
        $this->app->forgetInstance(ServiceStatutoryInvoiceMintProcessor::class);

        app(ServiceStatutoryInvoiceIssuanceCoordinator::class)->attempt(
            $order,
            $this->actor,
            ServiceStatutoryInvoiceMintTrigger::ServiceReferenceCompleted,
        );

        $pending = OutboxEvent::query()
            ->where('event_type', ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE)
            ->first();
        $this->assertNotNull($pending);
        $this->assertSame(OutboxEventStatus::Pending, $pending->status);
        $this->assertSame(0, StatutoryInvoice::query()->count());

        $this->app->forgetInstance(StatutoryInvoiceService::class);
        $this->app->forgetInstance(ServiceStatutoryInvoiceMintProcessor::class);
        $this->app->forgetInstance(ServiceStatutoryInvoiceIssuanceCoordinator::class);

        app(OutboxProcessorService::class)->process(1);

        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(OutboxEventStatus::Completed, $pending->fresh()->status);
    }

    public function test_outbox_retry_does_not_duplicate_invoice(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD3512504');

        app(OrderTransactionService::class)->assignTransactionId(
            $order,
            'TXN-IDEM',
            $this->actor,
            broadcast: false,
        );

        $this->assertSame(1, StatutoryInvoice::query()->count());

        OutboxEvent::query()->create([
            'idempotency_key' => ServiceStatutoryInvoiceMintOutboxWriter::idempotencyKeyForOrder($order->id),
            'event_type' => ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE,
            'aggregate_type' => ServiceStatutoryInvoiceMintOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $order->id,
            'payload' => [
                'order_pk' => $order->id,
                'order_id' => $order->order_id,
                'trigger' => 'invoice_retry',
            ],
            'status' => OutboxEventStatus::Pending,
            'attempts' => 0,
            'available_at' => now(),
        ]);

        app(OutboxProcessorService::class)->process(1);

        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(1, InvoiceSequenceAllocation::query()->count());
    }

    public function test_permanent_processor_failure_marks_outbox_failed_without_retry_storm(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD3512505');

        $invoiceService = Mockery::mock(StatutoryInvoiceService::class);
        $invoiceService->shouldReceive('issueFromSupportOrder')
            ->once()
            ->andThrow(ValidationException::withMessages(['eligibility' => ['Blocked permanently.']]));
        $invoiceService->shouldReceive('findBySource')->andReturn(null);
        $this->app->instance(StatutoryInvoiceService::class, $invoiceService);

        $event = OutboxEvent::query()->create([
            'idempotency_key' => ServiceStatutoryInvoiceMintOutboxWriter::idempotencyKeyForOrder($order->id).':perm',
            'event_type' => ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE,
            'aggregate_type' => ServiceStatutoryInvoiceMintOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $order->id,
            'payload' => [
                'order_pk' => $order->id,
                'order_id' => $order->order_id,
                'trigger' => 'invoice_retry',
            ],
            'status' => OutboxEventStatus::Pending,
            'attempts' => 0,
            'available_at' => now(),
        ]);

        app(OutboxProcessorService::class)->process(1);

        $this->assertSame(OutboxEventStatus::Failed, $event->fresh()->status);
        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_auto_close_failure_enqueues_retry_outbox(): void
    {
        [, $incident] = $this->deskOrderWithCommerce('RD3512506');

        $invoiceService = Mockery::mock(StatutoryInvoiceService::class);
        $invoiceService->shouldReceive('issueFromSupportOrder')
            ->atLeast()
            ->once()
            ->andThrow(new ServiceStatutoryInvoiceTemporaryFailureException('temporary'));
        $invoiceService->shouldReceive('findBySource')->andReturn(null);
        $this->app->instance(StatutoryInvoiceService::class, $invoiceService);
        $this->app->forgetInstance(ServiceStatutoryInvoiceIssuer::class);
        $this->app->forgetInstance(ServiceStatutoryInvoiceIssuanceCoordinator::class);

        $waiting = IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => Carbon::parse('2026-09-01 10:00:00'),
            'customer_followup_sent_at' => Carbon::parse('2026-09-01 10:00:00'),
            'sla_paused' => true,
            'reminder_policy_key' => 'customer_waiting_default',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);

        app(CustomerWaitingLifecycleService::class)->autoCloseForNoResponse(
            new PlannedAutomationAction(
                waitingState: $waiting->fresh(['incident.order', 'incident.supportAppointments']),
                policyKey: 'customer_waiting_default',
                scheduleStep: 1,
                actionType: AutomationPolicyActionType::AutoClose,
                actionKey: 'customer_not_responding',
                channel: null,
                scheduledAt: now(),
            ),
        );

        $this->assertTrue(
            OutboxEvent::query()
                ->where('event_type', ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE)
                ->where('status', OutboxEventStatus::Pending)
                ->exists(),
        );
    }

    public function test_reconciliation_attempts_eligible_workflow_completed_uninvoiced_order(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD3512511');
        $order->update([
            'transaction_id' => 'TXN-RECON',
            'completed_at' => now(),
        ]);

        $result = app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 50);

        $this->assertGreaterThanOrEqual(1, $result->attempted);
        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RD3512511')->count());
    }

    public function test_reconciliation_skips_already_invoiced_order(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD3512512');
        app(OrderTransactionService::class)->assignTransactionId($order, 'TXN-SKIP', $this->actor, broadcast: false);

        $before = StatutoryInvoice::query()->count();
        $result = app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 50);

        $this->assertSame($before, StatutoryInvoice::query()->count());
        $this->assertSame(0, $result->attempted);
        $this->assertNotNull(CommerceOrder::query()->where('source_id', 'RD3512512')->value('statutory_invoice_id'));
    }

    public function test_reconciliation_skips_paid_order_without_workflow_completion(): void
    {
        $this->deskOrderWithCommerce('RD3512513');

        $result = app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 50);

        $this->assertSame(0, StatutoryInvoice::query()->where('source_id', 'RD3512513')->count());
        $this->assertGreaterThanOrEqual(1, $result->noWorkflow);
    }

    public function test_finance_manual_issuance_makes_pending_retry_harmless(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD3512514');
        $commerce = CommerceOrder::query()->where('source_id', 'RD3512514')->firstOrFail();
        $order->update(['transaction_id' => 'TXN-MANUAL', 'completed_at' => now()]);

        OutboxEvent::query()->create([
            'idempotency_key' => ServiceStatutoryInvoiceMintOutboxWriter::idempotencyKeyForOrder($order->id),
            'event_type' => ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE,
            'aggregate_type' => ServiceStatutoryInvoiceMintOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $order->id,
            'payload' => [
                'order_pk' => $order->id,
                'order_id' => $order->order_id,
                'trigger' => 'invoice_retry',
            ],
            'status' => OutboxEventStatus::Pending,
            'attempts' => 0,
            'available_at' => now(),
        ]);

        app(StatutoryInvoiceService::class)->issueFromCommerceOrder($commerce, $this->actor);
        app(OutboxProcessorService::class)->process(1);

        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RD3512514')->count());
        $this->assertSame(1, InvoiceSequenceAllocation::query()->count());
        $this->assertSame(OutboxEventStatus::Completed, OutboxEvent::query()->first()->status);
    }

    public function test_payment_alone_still_does_not_issue(): void
    {
        $this->deskOrderWithCommerce('RD3512515');

        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 50);

        $this->assertSame(0, StatutoryInvoice::query()->where('source_id', 'RD3512515')->count());
    }

    public function test_channel_ingest_auto_issue_invoice_remains_off(): void
    {
        $this->assertFalse((bool) config('channel_ingest.auto_issue_invoice'));
    }

    /**
     * @return array{0: Order, 1: Incident}
     */
    private function deskOrderWithCommerce(string $orderId): array
    {
        $order = $this->deskOrder($orderId);
        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => 'Retry test case',
            'description' => 'Retry test case.',
            'status' => IncidentStatus::Open,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
        $this->commerceOrder($orderId);

        return [$order->fresh(), $incident];
    }

    private function deskOrder(string $orderId): Order
    {
        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => '7881953',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'cashfree_payment_id' => 'cf_'.$orderId,
            'status' => 'active',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
    }

    private function commerceOrder(string $sourceId): CommerceOrder
    {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'idempotency_key' => 'statutory:rdservice_in:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Customer',
            'billing_state' => 'Delhi',
            'branch_code' => 'DELHI-RETAIL',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 422.88,
            'tax_total' => 76.12,
            'order_value' => 499,
            'ordered_at' => '2026-09-06 10:00:00',
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'description' => 'Information technology (IT) consulting & support services',
            'hsn_sac' => '998314',
            'qty' => 1,
            'unit_price' => 422.88,
            'gst_percentage' => 18,
            'taxable_value' => 422.88,
            'tax_total' => 76.12,
            'line_total' => 499,
        ]);

        return $order->fresh(['items']);
    }
}
