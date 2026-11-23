<?php

namespace Tests\Feature\Dashboard;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\HardwareWorkspaceFilter;
use App\Enums\HardwareWorkspaceScope;
use App\Enums\RefundStatus;
use App\Enums\ShipmentStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryProduct;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\Shipment;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkQueue;
use App\Services\HardwareFulfilment\HardwareNeedsActionSqlQuery;
use App\Services\HardwareFulfilment\HardwarePhysicalStockCommitment;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class HardwareNeedsActionClassificationRegressionTest extends TestCase
{
    use RefreshDatabase;

    private InventoryProduct $cableProduct;

    private InventoryProduct $serializedProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seedProducts();
    }

    public function test_quantity_only_with_committed_stock_excluded_from_awaiting_serial_and_needs_action(): void
    {
        $fulfilment = $this->quantityFulfilment(
            'RBP2270-QTY',
            HardwareFulfilmentState::AwbAssigned,
            committed: true,
            awb: 'AWB2270',
        );

        $sql = app(HardwareNeedsActionSqlQuery::class);
        $from = HardwareFulfilmentEligibility::cutoffInstant();
        $to = Carbon::now(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $counts = $sql->filterCounts($from, $to);

        $this->assertSame(0, $counts[HardwareWorkspaceFilter::AwaitingSerial->value]);
        $this->assertSame(0, $counts[HardwareWorkspaceFilter::NeedsAction->value]);
        $this->assertSame(1, $counts[HardwareWorkspaceFilter::Shipping->value]);

        $page = $sql->page(
            HardwareWorkspaceScope::Active,
            HardwareWorkspaceFilter::NeedsAction,
            $from,
            $to,
            '',
            1,
            25,
        );
        $this->assertNotContains((int) $fulfilment->id, $page['fulfilment_ids']);
    }

    public function test_quantity_only_without_committed_stock_remains_awaiting_serial(): void
    {
        $this->quantityFulfilment('RBP2271-QTY', HardwareFulfilmentState::ReadyForFulfilment, committed: false);

        $sql = app(HardwareNeedsActionSqlQuery::class);
        $from = HardwareFulfilmentEligibility::cutoffInstant();
        $to = Carbon::now(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $counts = $sql->filterCounts($from, $to);

        $this->assertSame(1, $counts[HardwareWorkspaceFilter::AwaitingSerial->value]);
        $this->assertSame(1, $counts[HardwareWorkspaceFilter::NeedsAction->value]);
    }

    public function test_serialized_product_missing_serial_remains_awaiting_serial(): void
    {
        $this->serializedFulfilment('RDE990010', HardwareFulfilmentState::InvoiceIssued, serial: false);

        $counts = $this->filterCounts();

        $this->assertSame(1, $counts[HardwareWorkspaceFilter::AwaitingSerial->value]);
    }

    public function test_closed_refund_excludes_fulfilment_from_active_needs_action(): void
    {
        $fulfilment = $this->serializedFulfilment('RBP378-REF', HardwareFulfilmentState::ReadyForFulfilment, serial: false);
        $this->seedRefund($fulfilment->supportOrder, RefundStatus::Closed);

        $counts = $this->filterCounts();
        $this->assertSame(0, $counts[HardwareWorkspaceFilter::NeedsAction->value]);
    }

    public function test_pending_refund_remains_in_needs_action(): void
    {
        $this->serializedFulfilment('RBP762-REF', HardwareFulfilmentState::ReadyForFulfilment, serial: false);
        $order = Order::query()->where('order_id', 'RBP762-REF')->firstOrFail();
        $this->seedRefund($order, RefundStatus::Pending);

        $counts = $this->filterCounts();
        $this->assertSame(1, $counts[HardwareWorkspaceFilter::NeedsAction->value]);
    }

    public function test_closed_refund_takes_precedence_over_pending_duplicate(): void
    {
        $fulfilment = $this->serializedFulfilment('RBP858-REF', HardwareFulfilmentState::ReadyForFulfilment, serial: false);
        $order = $fulfilment->supportOrder;
        $this->seedRefund($order, RefundStatus::Pending, 'REF-PENDING');
        $this->seedRefund($order, RefundStatus::Closed, 'REF-CLOSED');

        $counts = $this->filterCounts();
        $this->assertSame(0, $counts[HardwareWorkspaceFilter::NeedsAction->value]);
    }

    public function test_awb_assigned_quantity_committed_not_awaiting_serial(): void
    {
        $this->quantityFulfilment(
            'RBP2020-QTY',
            HardwareFulfilmentState::AwbAssigned,
            committed: true,
            awb: 'AWB2020',
        );

        $counts = $this->filterCounts();
        $this->assertSame(0, $counts[HardwareWorkspaceFilter::AwaitingSerial->value]);
        $this->assertGreaterThanOrEqual(1, $counts[HardwareWorkspaceFilter::Shipping->value]);
    }

    public function test_shipped_and_synced_excluded_from_active_needs_action(): void
    {
        $this->quantityFulfilment('RBP-SHIP', HardwareFulfilmentState::Shipped, committed: true, awb: 'AWB1');
        $this->quantityFulfilment('RBP-SYNC', HardwareFulfilmentState::Synced, committed: true, awb: 'AWB2');

        $counts = $this->filterCounts();
        $this->assertSame(0, $counts[HardwareWorkspaceFilter::NeedsAction->value]);
    }

    public function test_cancelled_historical_duplicate_excluded_from_active_needs_action(): void
    {
        $this->serializedFulfilment(
            'RBP-DUP',
            HardwareFulfilmentState::CancelledHistoricalDuplicate,
            serial: true,
        );

        $counts = $this->filterCounts();
        $this->assertSame(0, $counts[HardwareWorkspaceFilter::NeedsAction->value]);
    }

    public function test_mapping_required_still_in_needs_action(): void
    {
        $this->serializedFulfilment('RDE-MAP', HardwareFulfilmentState::ReadyForFulfilment, serial: false, mapped: false);

        $counts = $this->filterCounts();
        $this->assertSame(1, $counts[HardwareWorkspaceFilter::MappingRequired->value]);
        $this->assertSame(1, $counts[HardwareWorkspaceFilter::NeedsAction->value]);
    }

    public function test_genuine_awb_pending_still_in_needs_action(): void
    {
        $this->serializedFulfilment(
            'RDE-AWB',
            HardwareFulfilmentState::ShipmentCreated,
            serial: true,
            invoice: true,
            bound: true,
        );

        $counts = $this->filterCounts();
        $this->assertSame(1, $counts[HardwareWorkspaceFilter::AwbPending->value]);
        $this->assertSame(1, $counts[HardwareWorkspaceFilter::NeedsAction->value]);
    }

    public function test_provider_rejected_not_in_awb_pending_but_in_exceptions_page(): void
    {
        $fulfilment = $this->serializedFulfilment(
            'RBP2536-REJ',
            HardwareFulfilmentState::ShipmentCreated,
            serial: true,
            invoice: true,
            bound: true,
            providerRejected: true,
        );

        $sql = app(HardwareNeedsActionSqlQuery::class);
        $from = HardwareFulfilmentEligibility::cutoffInstant();
        $to = Carbon::now(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);

        $counts = $sql->filterCounts($from, $to);
        $this->assertSame(0, $counts[HardwareWorkspaceFilter::AwbPending->value]);

        $exceptions = $sql->exceptionsPage($from, $to, '', 1, 25);
        $this->assertContains((int) $fulfilment->id, $exceptions['fulfilment_ids']);
    }

    public function test_needs_action_count_matches_unfiltered_total(): void
    {
        $this->serializedFulfilment('RDE-CNT1', HardwareFulfilmentState::ReadyForFulfilment, serial: false);
        $this->serializedFulfilment(
            'RDE-CNT2',
            HardwareFulfilmentState::ShipmentCreated,
            serial: true,
            invoice: true,
            bound: true,
        );

        $queue = app(HardwareFulfilmentWorkQueue::class);
        $from = HardwareFulfilmentEligibility::cutoffInstant();
        $to = Carbon::now(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $dashboard = $queue->dashboard($from, $to, '', HardwareWorkspaceScope::Active, HardwareWorkspaceFilter::NeedsAction);

        $this->assertSame(2, $dashboard['unfiltered_total']);
        $this->assertSame(2, $dashboard['filter_counts'][HardwareWorkspaceFilter::NeedsAction->value]);
    }

    public function test_quantity_committed_awb_order_appears_in_shipping_not_lost_between_queues(): void
    {
        $fulfilment = $this->quantityFulfilment(
            'RBP-SHIP-Q',
            HardwareFulfilmentState::AwbAssigned,
            committed: true,
            awb: 'AWBSHIP',
            track: 'out_for_pickup',
        );

        $sql = app(HardwareNeedsActionSqlQuery::class);
        $from = HardwareFulfilmentEligibility::cutoffInstant();
        $to = Carbon::now(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);

        $needs = $sql->page(HardwareWorkspaceScope::Active, HardwareWorkspaceFilter::NeedsAction, $from, $to, '', 1, 50);
        $shipping = $sql->page(HardwareWorkspaceScope::Active, HardwareWorkspaceFilter::Shipping, $from, $to, '', 1, 50);
        $outForPickup = $sql->page(HardwareWorkspaceScope::Active, HardwareWorkspaceFilter::OutForPickup, $from, $to, '', 1, 50);

        $id = (int) $fulfilment->id;
        $this->assertNotContains($id, $needs['fulfilment_ids']);
        $this->assertContains($id, $shipping['fulfilment_ids']);
        $this->assertContains($id, $outForPickup['fulfilment_ids']);
    }

    /**
     * @return array<string, int>
     */
    private function filterCounts(): array
    {
        $from = HardwareFulfilmentEligibility::cutoffInstant();
        $to = Carbon::now(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);

        return app(HardwareNeedsActionSqlQuery::class)->filterCounts($from, $to);
    }

    private function seedProducts(): void
    {
        $this->cableProduct = InventoryProduct::query()->create([
            'sku' => 'RBMFSTYPEC',
            'name' => 'Type-C cable',
            'hsn_code' => '85444299',
            'gst_percentage' => 18,
            'unit_price' => 299,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 1409,
            'inventory_product_id' => $this->cableProduct->id,
            'catalog_sku' => 'PMTMFSCCBL',
            'channel_sku' => '1409',
        ]);

        $this->serializedProduct = InventoryProduct::query()->create([
            'sku' => 'RBMFS110L1',
            'name' => 'MFS110',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 2549,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 946,
            'inventory_product_id' => $this->serializedProduct->id,
            'catalog_sku' => 'RBMFS110L1',
            'channel_sku' => '946',
        ]);
    }

    private function quantityFulfilment(
        string $sourceId,
        HardwareFulfilmentState $state,
        bool $committed,
        ?string $awb = null,
        ?string $track = null,
    ): HardwareFulfilment {
        $creator = User::factory()->create(['is_active' => true]);
        $order = Order::query()->create([
            'order_id' => $sourceId,
            'product_name' => 'Cable',
            'status' => 'active',
            'customer_name' => 'Buyer',
            'cashfree_payment_id' => 'cf_'.$sourceId,
            'created_by' => $creator->id,
        ]);
        $order->forceFill(['created_at' => '2026-09-07 10:00:00'])->save();

        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Buyer',
            'received_at' => now(),
            'ordered_at' => '2026-09-07 04:30:00',
            'support_order_id' => $order->id,
        ]);
        $item = CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'sku' => 'RBMFSTYPEC',
            'catalog_sku' => 'TYPEC',
            'model_id' => 1409,
            'shipping_line_kind' => HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
            'requires_shipping' => true,
            'description' => 'Cable',
            'qty' => 1,
            'unit_price' => 299,
            'gst_percentage' => 18,
            'taxable_value' => 253.39,
            'tax_total' => 45.61,
            'line_total' => 299,
        ]);

        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-'.$sourceId,
            'document_type' => 'tax_invoice',
            'status' => 'issued',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => 'invoice:'.$sourceId,
            'invoice_value' => 299,
            'issued_at' => now(),
        ]);
        $commerce->forceFill(['statutory_invoice_id' => $invoice->id])->save();

        $metadata = [];
        if ($committed) {
            $metadata = app(HardwarePhysicalStockCommitment::class)
                ->mergeQuantityCommitMetadata(
                    new HardwareFulfilment(['metadata' => []]),
                    [(int) $item->id => 1],
                );
        }

        $fulfilment = HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => $commerce->idempotency_key,
            'state' => $state,
            'support_order_id' => $order->id,
            'statutory_invoice_id' => $invoice->id,
            'ingested_at' => now(),
            'metadata' => $metadata,
            'awb' => $awb,
        ]);

        if ($awb !== null) {
            Shipment::query()->create([
                'shipment_no' => 'HW-'.$sourceId,
                'idempotency_key' => 'ship:'.$sourceId,
                'correlation_id' => (string) Str::uuid(),
                'commerce_order_id' => $commerce->id,
                'hardware_fulfilment_id' => $fulfilment->id,
                'provider' => 'shiprocket',
                'status' => ShipmentStatus::AwbAssigned,
                'external_order_id' => 'ext-'.$sourceId,
                'external_shipment_id' => 'shp-'.$sourceId,
                'awb' => $awb,
                'provider_track_normalized' => $track,
            ]);
            $fulfilment->forceFill(['shipment_id' => Shipment::query()->where('hardware_fulfilment_id', $fulfilment->id)->value('id')])->save();
        }

        return $fulfilment->fresh();
    }

    private function serializedFulfilment(
        string $sourceId,
        HardwareFulfilmentState $state,
        bool $serial = false,
        bool $mapped = true,
        bool $invoice = false,
        bool $bound = false,
        bool $providerRejected = false,
    ): HardwareFulfilment {
        $creator = User::factory()->create(['is_active' => true]);
        $order = Order::query()->create([
            'order_id' => $sourceId,
            'product_name' => 'MFS',
            'status' => 'active',
            'customer_name' => 'Buyer',
            'cashfree_payment_id' => 'cf_'.$sourceId,
            'created_by' => $creator->id,
        ]);
        $order->forceFill(['created_at' => '2026-09-07 10:00:00'])->save();

        $invoiceModel = null;
        if ($invoice) {
            $invoiceModel = StatutoryInvoice::query()->create([
                'invoice_number' => 'INV-'.$sourceId,
                'document_type' => 'tax_invoice',
                'status' => 'issued',
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'source_type' => 'commerce_order',
                'source_id' => $sourceId,
                'idempotency_key' => 'invoice:'.$sourceId,
                'invoice_value' => 2549,
                'issued_at' => now(),
            ]);
        }

        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Buyer',
            'received_at' => now(),
            'ordered_at' => '2026-09-07 04:30:00',
            'support_order_id' => $order->id,
            'statutory_invoice_id' => $invoiceModel?->id,
        ]);
        $item = CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'sku' => 'RBMFS110L1',
            'catalog_sku' => 'MFS',
            'model_id' => $mapped ? 946 : 99999,
            'shipping_line_kind' => HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
            'requires_shipping' => true,
            'description' => 'MFS',
            'qty' => 1,
            'unit_price' => 2549,
            'gst_percentage' => 18,
            'taxable_value' => 2160.17,
            'tax_total' => 388.83,
            'line_total' => 2549,
        ]);

        $fulfilment = HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => $commerce->idempotency_key,
            'state' => $state,
            'support_order_id' => $order->id,
            'statutory_invoice_id' => $invoiceModel?->id,
            'ingested_at' => now(),
        ]);

        if ($serial) {
            HardwareFulfilmentSerial::query()->create([
                'hardware_fulfilment_id' => $fulfilment->id,
                'commerce_order_item_id' => $item->id,
                'line_no' => 1,
                'position' => 1,
                'serial_number' => 'SN-'.$sourceId,
                'status' => HardwareFulfilmentSerialStatus::Allocated,
                'allocated_at' => now(),
            ]);
        }

        if ($bound || $providerRejected) {
            Shipment::query()->create([
                'shipment_no' => 'HW-'.$sourceId,
                'idempotency_key' => 'ship:'.$sourceId,
                'correlation_id' => (string) Str::uuid(),
                'commerce_order_id' => $commerce->id,
                'hardware_fulfilment_id' => $fulfilment->id,
                'provider' => 'shiprocket',
                'status' => ShipmentStatus::Created,
                'external_order_id' => $bound ? 'ext-'.$sourceId : null,
                'external_shipment_id' => $bound ? 'shp-'.$sourceId : null,
                'failure_class' => $providerRejected ? 'provider_rejected' : null,
                'last_error' => $providerRejected ? 'Shiprocket rejected AWB assignment.' : null,
            ]);
        }

        $fulfilment->setRelation('supportOrder', $order);

        return $fulfilment->fresh(['supportOrder']);
    }

    private function seedRefund(Order $order, RefundStatus $status, ?string $reference = null): RefundRequest
    {
        $actor = User::factory()->create(['is_active' => true]);

        return RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => $reference ?? 'REF-'.uniqid(),
            'amount' => 100,
            'reason' => 'P-24-04 regression seed.',
            'status' => $status->value,
            'requested_by' => $actor->id,
        ]);
    }
}
