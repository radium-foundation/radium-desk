<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryUserBranch;
use App\Models\Order;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalClassifier;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentProductLines;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentParcelSnapshotService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Services\HardwareFulfilment\HardwareShipmentEligibility;
use App\Services\Inventory\InventoryStockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardwareNonSerializedMultiSkuParcelTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private InventoryProduct $typeC;

    private InventoryProduct $usb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->withoutVite();
        $this->operator = User::factory()->create(['is_active' => true]);
        $this->operator->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        $this->typeC = InventoryProduct::query()->create([
            'sku' => 'RBMFSTYPEC',
            'name' => 'Mantra Type-C cable',
            'hsn_code' => '85444299',
            'gst_percentage' => 18,
            'unit_price' => 299,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        $this->usb = InventoryProduct::query()->create([
            'sku' => 'RBMFSUSBCB',
            'name' => 'Mantra USB cable',
            'hsn_code' => '85444299',
            'gst_percentage' => 18,
            'unit_price' => 299,
            'is_serialized' => false,
            'is_active' => true,
        ]);

        foreach ([1409 => $this->typeC, 1410 => $this->usb] as $modelId => $product) {
            ChannelSkuMap::query()->create([
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'model_id' => $modelId,
                'inventory_product_id' => $product->id,
                'catalog_sku' => $modelId === 1409 ? 'PMTMFSCCBL' : 'PMTMFSUCBL',
                'channel_sku' => (string) $modelId,
            ]);
        }
    }

    public function test_multi_sku_quantity_stock_enables_measured_parcel_and_operator_labels(): void
    {
        $fulfilment = $this->invoicedMultiCableFulfilment('RBP103');
        $snapshots = app(HardwareFulfilmentParcelSnapshotService::class);

        $this->assertTrue($snapshots->requiresMeasuredParcel($fulfilment));
        $this->assertSame(
            'Multi-SKU hardware cannot use catalog packaging. A measured order parcel is required.',
            $snapshots->ineligibleReason($fulfilment),
        );
        $this->assertNull($snapshots->measuredIneligibleReason($fulfilment));
        $this->assertTrue($snapshots->canAttachMeasured($fulfilment));

        $ready = app(HardwareShipmentEligibility::class)->inspect($fulfilment);
        $this->assertTrue($ready->canAttachMeasuredParcel);
        $this->assertContains('Parcel packaging not attached', $ready->blockers);

        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment, $ready);
        $this->assertSame('Enter Package Dimensions', $row->nextAction);
        $this->assertSame('hardware-parcel-measure', $row->nextAnchor);

        $catalog = HardwareFulfilmentProductLines::resolve($fulfilment->commerceOrder, null);
        $this->assertSame('Mantra MFS110 Type-C Cable · 1 Q +1', $catalog['compact']);
        $this->assertSame([
            ['label' => 'Mantra MFS110 Type-C Cable', 'qty' => 1],
            ['label' => 'Mantra MFS110 USB Cable', 'qty' => 1],
        ], $catalog['lines']);
    }

    public function test_start_shipment_action_dialog_shows_measured_parcel_form_not_open_fulfilment_link(): void
    {
        $fulfilment = $this->invoicedMultiCableFulfilment('RBP103');
        $ready = app(HardwareShipmentEligibility::class)->inspect($fulfilment);
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment, $ready);

        $this->assertSame('Enter Package Dimensions', $row->nextAction);

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.action-dialog', $fulfilment))
            ->assertOk()
            ->assertSee('Package Dimensions — Complete Packed Shipment')
            ->assertSee('Mantra MFS110 Type-C Cable · Qty 1')
            ->assertSee('Mantra MFS110 USB Cable · Qty 1')
            ->assertSee('Quantity')
            ->assertSee('2', false)
            ->assertDontSee('Open Fulfilment');
    }

    public function test_measured_parcel_attach_clears_blocker_for_courier_flow(): void
    {
        $fulfilment = $this->invoicedMultiCableFulfilment('RBP103');

        app(HardwareFulfilmentParcelSnapshotService::class)->attachMeasured($fulfilment, [
            'length' => 25,
            'breadth' => 18,
            'height' => 8,
            'weight' => 0.35,
        ], $this->operator);

        $ready = app(HardwareShipmentEligibility::class)->inspect($fulfilment->fresh());
        $this->assertNotContains('Parcel packaging not attached', $ready->blockers);
        $this->assertSame('measured', $ready->parcelSource);
        $this->assertSame('0.35 kg · 25×18×8 cm', $ready->parcel);
        $this->assertFalse($ready->canAttachMeasuredParcel);
    }

    private function invoicedMultiCableFulfilment(string $sourceId): HardwareFulfilment
    {
        $branch = InventoryBranch::query()->firstOrCreate(
            ['code' => 'DELHI-RETAIL'],
            ['name' => 'Delhi Retail', 'is_active' => true],
        );
        InventoryUserBranch::query()->firstOrCreate([
            'user_id' => $this->operator->id,
            'branch_id' => $branch->id,
        ]);
        app(InventoryStockService::class)->stockInQuantity($this->typeC, $branch, 5, $this->operator);
        app(InventoryStockService::class)->stockInQuantity($this->usb, $branch, 5, $this->operator);

        $creator = User::factory()->create(['is_active' => true]);
        $order = Order::query()->create([
            'order_id' => $sourceId,
            'product_name' => 'Biometric Replacement Cable',
            'status' => 'active',
            'customer_name' => 'Cable Buyer',
            'created_by' => $creator->id,
        ]);
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
            'received_at' => now(),
            'customer_name' => 'Cable Buyer',
            'customer_phone' => '9000000099',
            'customer_email' => 'buyer@example.com',
            'shipping_address_structured' => [
                'line1' => '12 Test Lane',
                'city' => 'Delhi',
                'state' => 'Delhi',
                'pincode' => '110001',
                'country' => 'India',
            ],
            'support_order_id' => $order->id,
        ]);

        $items = [];
        foreach ([1409, 1410] as $index => $modelId) {
            $items[] = CommerceOrderItem::query()->create([
                'commerce_order_id' => $commerce->id,
                'line_no' => $index + 1,
                'sku' => (string) $modelId,
                'model_id' => $modelId,
                'shipping_line_kind' => HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
                'requires_shipping' => true,
                'description' => 'Biometric Replacement Cable',
                'qty' => 1,
                'unit_price' => 299,
                'gst_percentage' => 18,
                'taxable_value' => 253.39,
                'tax_total' => 45.61,
                'line_total' => 299,
            ]);
        }

        $fulfilment = HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'support_order_id' => $order->id,
            'source_id' => $sourceId,
            'source_type' => 'commerce_order',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'idempotency_key' => $commerce->idempotency_key,
            'state' => HardwareFulfilmentState::ReadyForFulfilment,
            'ingested_at' => now(),
            'fulfilment_branch_id' => $branch->id,
        ]);

        app(HardwareFulfilmentWorkflowService::class)->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);
        app(HardwareSerialAllocationService::class)->allocateQuantityStock($fulfilment->fresh(['commerceOrder.items']), $this->operator);

        $fulfilment = $fulfilment->fresh(['commerceOrder.items']);
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-'.$sourceId,
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => 'invoice:'.$sourceId,
            'invoice_value' => 598,
            'issued_at' => now(),
        ]);
        $fulfilment->forceFill([
            'state' => HardwareFulfilmentState::InvoiceIssued,
            'statutory_invoice_id' => $invoice->id,
        ])->save();
        $commerce->forceFill(['statutory_invoice_id' => $invoice->id])->save();

        return $fulfilment->fresh(['commerceOrder.items', 'serials']);
    }
}
