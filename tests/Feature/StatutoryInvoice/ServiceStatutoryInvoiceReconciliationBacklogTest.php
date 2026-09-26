<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OutboxEventStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\Incident;
use App\Models\InvoiceSequenceAllocation;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\HardwareFulfilment\HardwareCommerceStatutoryInvoiceGuard;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\IncidentReferenceService;
use App\Services\OrderTransactionService;
use App\Services\Outbox\OutboxProcessorService;
use App\Services\StatutoryInvoice\ServiceStatutoryInvoiceMintOutboxWriter;
use App\Services\StatutoryInvoice\ServiceStatutoryInvoiceReconciliationService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ServiceStatutoryInvoiceReconciliationBacklogTest extends TestCase
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
            'service_statutory_invoice.reconciliation.max_scan_per_run' => 20_000,
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

    public function test_reconciliation_reaches_eligible_service_order_beyond_first_hundred_candidates(): void
    {
        $this->seedNoWorkflowServiceBacklog(110, 'RD3518000');
        [$target] = $this->deskOrderWithCommerce('RD3518115');
        $target->update(['transaction_id' => 'TXN-BEYOND-100', 'completed_at' => now()]);

        $result = app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 5);

        $this->assertGreaterThan(100, $result->scanned);
        $this->assertGreaterThanOrEqual(1, $result->attempted);
        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RD3518115')->count());
    }

    public function test_hardware_candidates_are_excluded_from_reconciliation_scan(): void
    {
        $this->seedHardwareBacklog(120);
        [$target] = $this->deskOrderWithCommerce('RD3518200');
        $target->update(['transaction_id' => 'TXN-HW-MIX', 'completed_at' => now()]);

        $result = app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 3);

        $this->assertSame(1, $result->scanned);
        $this->assertSame(1, $result->attempted);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RD3518200')->count());
    }

    public function test_ineligible_and_no_workflow_candidates_do_not_block_later_eligible_orders(): void
    {
        $this->seedNoWorkflowServiceBacklog(110, 'RD3518300');
        [$target] = $this->deskOrderWithCommerce('RD3518410');
        $target->update(['transaction_id' => 'TXN-NO-WF-BLOCK', 'completed_at' => now()]);

        $result = app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 5);

        $this->assertGreaterThan(100, $result->scanned);
        $this->assertGreaterThanOrEqual(1, $result->noWorkflow);
        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RD3518410')->count());
    }

    public function test_b2c_service_order_beyond_blocked_backlog_is_minted(): void
    {
        $this->seedNoWorkflowServiceBacklog(105, 'RD3518400');
        [$target] = $this->deskOrderWithCommerce('RD3518506');
        $target->update(['transaction_id' => 'TXN-B2C-BEYOND', 'completed_at' => now()]);

        $result = app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 10);

        $this->assertGreaterThan(100, $result->scanned);
        $invoice = StatutoryInvoice::query()->where('source_id', 'RD3518506')->first();
        $this->assertNotNull($invoice);
        $this->assertNull($invoice->buyer_gstin);
    }

    public function test_b2b_gstin_state_mismatch_issues_b2c_invoice_without_blocking_backlog(): void
    {
        $this->seedHardwareBacklog(105);
        [$blocked] = $this->deskOrderWithCommerce('RD3518600', b2b: true, billingState: 'Delhi', buyerGstin: '09AAJFV1437D1Z7');
        $blocked->update(['transaction_id' => 'TXN-B2B-BLOCK', 'completed_at' => now()]);
        [$target] = $this->deskOrderWithCommerce('RD3518601');
        $target->update(['transaction_id' => 'TXN-B2C-AFTER-B2B', 'completed_at' => now()]);

        $result = app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 5);

        $mismatchInvoice = StatutoryInvoice::query()->where('source_id', 'RD3518600')->first();
        $this->assertNotNull($mismatchInvoice);
        $this->assertNull($mismatchInvoice->buyer_gstin);
        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RD3518601')->count());
        $this->assertGreaterThanOrEqual(2, $result->attempted);
        $this->assertSame(
            0,
            OutboxEvent::query()
                ->where('event_type', ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE)
                ->where('status', OutboxEventStatus::Failed)
                ->count(),
        );
    }

    public function test_gst_mismatch_b2c_invoice_is_not_reissued_after_commerce_remediation(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD3518700', b2b: true, billingState: 'Delhi', buyerGstin: '09AAJFV1437D1Z7');
        $order->update(['transaction_id' => 'TXN-REMEDIATE', 'completed_at' => now()]);

        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 5);
        $invoice = StatutoryInvoice::query()->where('source_id', 'RD3518700')->first();
        $this->assertNotNull($invoice);
        $this->assertNull($invoice->buyer_gstin);

        CommerceOrder::query()->where('source_id', 'RD3518700')->update([
            'billing_state' => 'Uttar Pradesh',
            'place_of_supply_state' => 'Uttar Pradesh',
            'billing_address_structured' => [
                'state' => 'Uttar Pradesh',
                'city' => 'Lucknow',
                'pincode' => '226001',
            ],
        ]);

        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 5);
        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RD3518700')->count());
        $this->assertNull(StatutoryInvoice::query()->where('source_id', 'RD3518700')->value('buyer_gstin'));
    }

    public function test_reconciliation_skips_already_invoiced_order_in_backlog(): void
    {
        $this->seedNoWorkflowServiceBacklog(105, 'RD3518800');
        [$order] = $this->deskOrderWithCommerce('RD3518905');
        app(OrderTransactionService::class)->assignTransactionId($order, 'TXN-ALREADY', $this->actor, broadcast: false);

        $before = StatutoryInvoice::query()->count();
        $result = app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 10);

        $this->assertSame($before, StatutoryInvoice::query()->count());
        $this->assertSame(0, $result->attempted);
        $this->assertNotNull(CommerceOrder::query()->where('source_id', 'RD3518905')->value('statutory_invoice_id'));
    }

    public function test_repeated_reconciliation_does_not_create_duplicate_statutory_invoices(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD3519000');
        $order->update(['transaction_id' => 'TXN-NO-DUP', 'completed_at' => now()]);

        $service = app(ServiceStatutoryInvoiceReconciliationService::class);
        $service->reconcile(limit: 5);
        $service->reconcile(limit: 5);

        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RD3519000')->count());
        $this->assertSame(1, InvoiceSequenceAllocation::query()->count());
    }

    public function test_reconciliation_overlapping_pending_outbox_remains_idempotent(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD3519100');
        $order->update(['transaction_id' => 'TXN-OUTBOX', 'completed_at' => now()]);

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

        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 5);
        app(OutboxProcessorService::class)->process(1);

        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RD3519100')->count());
        $this->assertSame(OutboxEventStatus::Completed, OutboxEvent::query()->first()->status);
    }

    public function test_manual_finance_issue_remains_valid_alongside_reconciliation(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD3519200');
        $commerce = CommerceOrder::query()->where('source_id', 'RD3519200')->firstOrFail();
        $order->update(['transaction_id' => 'TXN-MANUAL-RECON', 'completed_at' => now()]);

        app(StatutoryInvoiceService::class)->issueFromCommerceOrder($commerce, $this->actor);
        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 10);

        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RD3519200')->count());
        $this->assertSame(1, InvoiceSequenceAllocation::query()->count());
    }

    public function test_hardware_serial_path_remains_separate_from_service_reconciliation(): void
    {
        $hardware = $this->paidHardwareCommerceWithoutFulfilment('RDE3519300');

        $this->assertTrue(HardwareFulfilmentEligibility::requiresSerialAllocatedInvoice($hardware));
        $this->assertTrue(app(HardwareCommerceStatutoryInvoiceGuard::class)->requiresHardwareSerialPath($hardware));

        $result = app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 5);

        $this->assertSame(0, $result->scanned);
        $this->assertSame(0, StatutoryInvoice::query()->where('source_id', 'RDE3519300')->count());
        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_mixed_supported_channels_are_reconciled(): void
    {
        $this->seedHardwareBacklog(3);

        [$rdOrder] = $this->deskOrderWithCommerce('RD3519400');
        $rdOrder->update(['transaction_id' => 'TXN-RD', 'completed_at' => now()]);

        [$rbOrder] = $this->deskOrderWithCommerce('RB3519402', channel: StatutoryInvoiceChannel::RadiumBoxCom);
        $rbOrder->update(['transaction_id' => 'TXN-RB', 'completed_at' => now()]);

        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 10);

        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RD3519400')->count());
        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RB3519402')->count());
    }

    public function test_rdservice_net_ra_prefix_orders_are_reconciled(): void
    {
        [$raOrder] = $this->deskOrderWithCommerce('RA3507999', channel: StatutoryInvoiceChannel::RdServiceNet);
        $raOrder->update(['transaction_id' => 'TXN-RA', 'completed_at' => now()]);

        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 5);

        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RA3507999')->count());
    }

    private function seedHardwareBacklog(int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            $this->paidHardwareCommerceWithoutFulfilment('RDE9'.str_pad((string) $index, 5, '0', STR_PAD_LEFT));
        }
    }

    private function seedNoWorkflowServiceBacklog(int $count, string $idPrefix): void
    {
        for ($index = 0; $index < $count; $index++) {
            $this->commerceOrder($idPrefix.str_pad((string) $index, 2, '0', STR_PAD_LEFT));
        }
    }

    /**
     * @return array{0: Order}
     */
    private function deskOrderWithCommerce(
        string $orderId,
        StatutoryInvoiceChannel $channel = StatutoryInvoiceChannel::RdServiceIn,
        bool $b2b = false,
        ?string $billingState = 'Delhi',
        ?string $buyerGstin = null,
    ): array {
        $order = $this->deskOrder($orderId);
        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => 'Reconciliation backlog test',
            'description' => 'Reconciliation backlog test.',
            'status' => IncidentStatus::Open,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
        $this->commerceOrder($orderId, $channel, $b2b, $billingState, $buyerGstin);

        return [$order->fresh()];
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

    private function commerceOrder(
        string $sourceId,
        StatutoryInvoiceChannel $channel = StatutoryInvoiceChannel::RdServiceIn,
        bool $b2b = false,
        ?string $billingState = 'Delhi',
        ?string $buyerGstin = null,
    ): CommerceOrder {
        $channelValue = $channel->value;
        $hsnSac = $channel === StatutoryInvoiceChannel::RdServiceNet ? '998313' : '998314';
        $sku = $channel === StatutoryInvoiceChannel::RdServiceNet ? 'RD-SVC' : null;
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => $channel,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'idempotency_key' => 'statutory:'.$channelValue.':commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Customer',
            'buyer_gstin' => $b2b ? $buyerGstin : null,
            'seller_gstin' => $channel === StatutoryInvoiceChannel::RdServiceNet ? '07AAICP1128M1Z9' : null,
            'billing_state' => $billingState,
            'billing_address_structured' => $b2b ? [
                'state' => $billingState,
                'city' => $billingState === 'Uttar Pradesh' ? 'Lucknow' : 'New Delhi',
                'pincode' => $billingState === 'Uttar Pradesh' ? '226001' : '110001',
            ] : null,
            'branch_code' => 'DELHI-RETAIL',
            'place_of_supply_state' => $billingState,
            'taxable_value' => 422.88,
            'tax_total' => 76.12,
            'order_value' => 499,
            'ordered_at' => '2026-09-06 10:00:00',
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'description' => 'Information technology (IT) consulting & support services',
            'sku' => $sku,
            'hsn_sac' => $hsnSac,
            'qty' => 1,
            'unit_price' => 422.88,
            'gst_percentage' => 18,
            'taxable_value' => 422.88,
            'tax_total' => 76.12,
            'line_total' => 499,
        ]);

        return $order->fresh(['items']);
    }

    private function paidHardwareCommerceWithoutFulfilment(string $sourceId): CommerceOrder
    {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Hardware Buyer',
            'buyer_gstin' => null,
            'billing_state' => 'Delhi',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 2583.90,
            'tax_total' => 465.10,
            'order_value' => 3049,
            'ordered_at' => '2026-09-08 10:00:00',
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'description' => 'MSO1300',
            'sku' => '951',
            'qty' => 1,
            'unit_price' => 3049,
            'hsn_sac' => '84716050',
            'gst_percentage' => 18,
            'taxable_value' => 2583.90,
            'tax_total' => 465.10,
            'line_total' => 3049,
            'shipping_line_kind' => 'physical_merchandise',
            'requires_shipping' => true,
            'model_id' => 951,
        ]);

        return $order->fresh(['items']);
    }
}
