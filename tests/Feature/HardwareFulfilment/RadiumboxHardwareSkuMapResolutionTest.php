<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\InventoryProduct;
use App\Models\Order;
use App\Models\User;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalClassifier;
use App\Services\HardwareFulfilment\Data\HardwareShipmentReadiness;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareSkuMapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RadiumboxHardwareSkuMapResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMappedProducts();
        $this->artisan('desk:seed-radiumbox-hardware-sku-maps', ['--apply' => true])
            ->assertSuccessful();
    }

    public function test_rbp449_resolves_mfs500_model_id_to_rbmfs500fp(): void
    {
        $product = app(HardwareSkuMapService::class)->requireProduct(
            StatutoryInvoiceChannel::RadiumBoxCom,
            625,
        );

        $this->assertSame('RBMFS500FP', $product->sku);
        $this->assertTrue($product->is_serialized);

        $row = $this->classifyReadyFulfilment('RBP449', 625, $product->is_serialized);

        $this->assertSame('Allocate Serial', $row->nextAction);
        $this->assertNotSame('Product mapping required', $row->operatorStatus());
    }

    public function test_rbp447_resolves_morpho_usb_model_id_to_rbmsousbcb(): void
    {
        $product = app(HardwareSkuMapService::class)->requireProduct(
            StatutoryInvoiceChannel::RadiumBoxCom,
            1420,
        );

        $this->assertSame('RBMSOUSBCB', $product->sku);
        $this->assertFalse($product->is_serialized);

        $row = $this->classifyReadyFulfilment('RBP447', 1420, $product->is_serialized);

        $this->assertSame('Allocate Stock', $row->nextAction);
        $this->assertNotSame('Product mapping required', $row->operatorStatus());
    }

    public function test_unmapped_model_id_still_shows_product_mapping_required(): void
    {
        $row = $this->classifyReadyFulfilment('RBP999', 99999, true);

        $this->assertSame('Product mapping required', $row->operatorStatus());
        $this->assertSame('View', $row->nextAction);
        $this->assertFalse($row->mutatingAction);
    }

    private function classifyReadyFulfilment(string $sourceId, int $modelId, bool $serialized): mixed
    {
        $fulfilment = $this->readyFulfilment($sourceId, $modelId);
        $ready = $this->readiness([
            'alreadyCreated' => false,
            'serials' => [],
            'invoice' => null,
            'quantity' => 1,
            'stockCommitted' => false,
            'serialized' => $serialized,
        ]);

        return app(HardwareFulfilmentOperationalClassifier::class)
            ->fromFulfilment($fulfilment->fresh(['commerceOrder.items', 'supportOrder']), $ready);
    }

    private function readyFulfilment(string $sourceId, int $modelId): HardwareFulfilment
    {
        $creator = User::factory()->create(['is_active' => true]);
        $support = Order::query()->create([
            'order_id' => $sourceId,
            'product_name' => 'Hardware',
            'status' => 'active',
            'created_by' => $creator->id,
            'cashfree_payment_id' => 'cf_'.$sourceId,
            'created_at' => '2026-09-23 10:00:00',
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
            'ordered_at' => '2026-09-23 10:00:00',
            'support_order_id' => $support->id,
        ]);
        CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'sku' => (string) $modelId,
            'shipping_line_kind' => HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
            'requires_shipping' => true,
            'model_id' => $modelId,
            'description' => 'Hardware '.$modelId,
            'qty' => 1,
            'unit_price' => 1000,
            'gst_percentage' => 18,
            'taxable_value' => 847.46,
            'tax_total' => 152.54,
            'line_total' => 1000,
        ]);

        return HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => $commerce->idempotency_key,
            'state' => HardwareFulfilmentState::ReadyForFulfilment,
            'support_order_id' => $support->id,
            'ingested_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function readiness(array $overrides = []): HardwareShipmentReadiness
    {
        return new HardwareShipmentReadiness(
            canCreate: (bool) ($overrides['canCreate'] ?? false),
            blockers: [],
            status: 'Created',
            pickupBranch: null,
            pickupLocation: null,
            shipTo: null,
            parcel: null,
            invoice: $overrides['invoice'] ?? 'INV-1',
            serials: $overrides['serials'] ?? [],
            order: 'RBP1',
            product: 'Hardware',
            alreadyCreated: $overrides['alreadyCreated'] ?? true,
            actionLabel: $overrides['actionLabel'] ?? 'Create Shipment',
            awb: array_key_exists('awb', $overrides) ? $overrides['awb'] : 'AWB1',
            canFetchCourierOptions: (bool) ($overrides['canFetchCourierOptions'] ?? false),
            canSelectCourier: (bool) ($overrides['canSelectCourier'] ?? false),
            courierOptions: $overrides['courierOptions'] ?? [],
            selectedCourierId: $overrides['selectedCourierId'] ?? null,
            selectedCourierName: $overrides['selectedCourierName'] ?? null,
            labelUrl: array_key_exists('labelUrl', $overrides) ? $overrides['labelUrl'] : '/label.pdf',
            pickupStatus: $overrides['pickupStatus'] ?? 'Not requested',
            manifestStatus: $overrides['manifestStatus'] ?? 'Not generated',
            packageBeforeLabelRecorded: $overrides['packageBeforeLabelRecorded'] ?? false,
            packageLabelAppliedRecorded: $overrides['packageLabelAppliedRecorded'] ?? false,
            canAttachMeasuredParcel: (bool) ($overrides['canAttachMeasuredParcel'] ?? false),
            providerRejection: $overrides['providerRejection'] ?? null,
            canConfirmRecommendedCourier: (bool) ($overrides['canConfirmRecommendedCourier'] ?? false),
            canShipAndGenerateLabel: (bool) ($overrides['canShipAndGenerateLabel'] ?? false),
            recommendedCourierLabel: $overrides['recommendedCourierLabel'] ?? null,
            orchestrationAutoSelectEnabled: (bool) ($overrides['orchestrationAutoSelectEnabled'] ?? false),
            quantity: $overrides['quantity'] ?? null,
            stockCommitted: (bool) ($overrides['stockCommitted'] ?? false),
        );
    }

    private function seedMappedProducts(): void
    {
        foreach ([
            ['RBHYP2003T', 347, true],
            ['RBBIOC600C', 1749, true],
            ['RBMFSTYPEC', 1409, false],
            ['RBMFSUSBCB', 1410, false],
            ['RBMSOUSBCB', 1420, false],
            ['RBMFS500FP', 625, true],
            ['RBWM112MZ', 340, false],
            ['RBSMOOTHED', 1753, true],
        ] as [$sku, $modelId, $serialized]) {
            $product = InventoryProduct::query()->create([
                'sku' => $sku,
                'name' => $sku,
                'hsn_code' => $serialized ? '84716050' : '85444299',
                'gst_percentage' => 18,
                'unit_price' => 100,
                'is_serialized' => $serialized,
                'is_active' => true,
            ]);
            ChannelSkuMap::query()->create([
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'model_id' => 9990 + $modelId,
                'inventory_product_id' => $product->id,
                'catalog_sku' => 'EXISTING-'.$modelId,
                'channel_sku' => '9990',
            ]);
        }
    }
}
