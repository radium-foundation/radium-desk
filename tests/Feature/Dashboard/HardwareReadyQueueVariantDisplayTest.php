<?php

namespace Tests\Feature\Dashboard;

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
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentProductLines;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkQueue;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HardwareReadyQueueVariantDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->withoutVite();
        $this->seedCableMaps();
    }

    public function test_hardware_queue_renders_exact_cable_variants_for_rbp103_and_rde318526(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->fulfilmentWithItem('RBP103', 1409, 'Biometric Replacement Cable');
        $this->fulfilmentWithItem('RBP103-USB', 1410, 'Biometric Replacement Cable');
        $this->fulfilmentWithItem('RDE318526', 1410, 'Biometric Replacement Cable');

        $html = $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'hardware', 'hw_filter' => 'all']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Mantra MFS110 Type-C', $html);
        $this->assertStringContainsString('Mantra MFS110 USB', $html);
        $this->assertSame(2, substr_count($html, 'Mantra MFS110 USB'));
    }

    public function test_generic_replacement_cable_alone_is_not_used_when_model_id_is_known(): void
    {
        $commerce = $this->commerceWithItem('RBP103-UNIT', 1410, 'Biometric Replacement Cable');
        $catalog = HardwareFulfilmentProductLines::resolve($commerce, null);

        $this->assertFalse($catalog['missing']);
        $this->assertSame('Mantra MFS110 USB', $catalog['lines'][0]['label']);
        $this->assertStringNotContainsString('Biometric Replacement Cable', $catalog['compact']);
    }

    public function test_non_variant_product_retains_description(): void
    {
        $commerce = $this->commerceWithItem('RBP206', 347, 'Feitian ePass HYP2003 Auto USB Token');
        $catalog = HardwareFulfilmentProductLines::resolve($commerce, null);

        $this->assertSame('Feitian ePass HYP2003 Auto USB Token', $catalog['lines'][0]['label']);
    }

    public function test_hardware_queue_route_loads_with_hardware_filter(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->fulfilmentWithItem('RDE318338', 1749, 'BioEnable C600');

        $this->actingAs($admin)
            ->get('/dashboard?queue=hardware')
            ->assertOk()
            ->assertSee('BioEnable C600 Face Camera · C600');
    }

    public function test_work_queue_compact_line_uses_variant_labels(): void
    {
        $this->fulfilmentWithItem('RDE318526', 1410, 'Biometric Replacement Cable');
        $from = HardwareFulfilmentEligibility::cutoffInstant();
        $to = Carbon::now(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $rows = app(HardwareFulfilmentWorkQueue::class)->dashboard($from, $to, 'RDE318526');

        $this->assertSame(1, $rows['unfiltered_total']);
        $this->assertStringContainsString('Mantra MFS110 USB', $rows['rows'][0]->product ?? '');
    }

    private function seedCableMaps(): void
    {
        $typeC = InventoryProduct::query()->create([
            'sku' => 'RBMFSTYPEC',
            'name' => 'Mantra Type-C cable',
            'hsn_code' => '85444299',
            'gst_percentage' => 18,
            'unit_price' => 299,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        $usb = InventoryProduct::query()->create([
            'sku' => 'RBMFSUSBCB',
            'name' => 'Mantra USB cable',
            'hsn_code' => '85444299',
            'gst_percentage' => 18,
            'unit_price' => 299,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        $token = InventoryProduct::query()->create([
            'sku' => 'RBHYP2003T',
            'name' => 'Feitian token',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 1500,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $camera = InventoryProduct::query()->create([
            'sku' => 'RBBIOC600C',
            'name' => 'BioEnable C600',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 4500,
            'is_serialized' => true,
            'is_active' => true,
        ]);

        foreach ([
            [347, $token, 'PFEHYP2003'],
            [1749, $camera, 'RBBIOC600C'],
            [1409, $typeC, 'PMTMFSCCBL'],
            [1410, $usb, 'PMTMFSUCBL'],
        ] as [$modelId, $product, $catalogSku]) {
            ChannelSkuMap::query()->create([
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'model_id' => $modelId,
                'inventory_product_id' => $product->id,
                'catalog_sku' => $catalogSku,
                'channel_sku' => (string) $modelId,
            ]);
        }
    }

    private function fulfilmentWithItem(string $sourceId, int $modelId, string $description): HardwareFulfilment
    {
        $creator = User::factory()->create(['is_active' => true]);
        $order = Order::query()->create([
            'order_id' => $sourceId,
            'product_name' => $description,
            'status' => 'active',
            'customer_name' => 'Buyer',
            'cashfree_payment_id' => 'cf_'.$sourceId,
            'created_by' => $creator->id,
        ]);
        $order->forceFill(['created_at' => '2026-09-07 10:00:00'])->save();

        $commerce = $this->commerceWithItem($sourceId, $modelId, $description, $order);

        return HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'support_order_id' => $order->id,
            'source_id' => $sourceId,
            'source_type' => 'commerce_order',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'idempotency_key' => $commerce->idempotency_key,
            'state' => HardwareFulfilmentState::ReadyForFulfilment,
            'ingested_at' => now(),
        ]);
    }

    private function commerceWithItem(string $sourceId, int $modelId, string $description, ?Order $order = null): CommerceOrder
    {
        if ($order === null) {
            $creator = User::factory()->create(['is_active' => true]);
            $order = Order::query()->create([
                'order_id' => $sourceId,
                'product_name' => $description,
                'status' => 'active',
                'created_by' => $creator->id,
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
            'received_at' => now(),
            'ordered_at' => '2026-09-07 04:30:00',
            'support_order_id' => $order->id,
        ]);
        CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'sku' => (string) $modelId,
            'model_id' => $modelId,
            'shipping_line_kind' => HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
            'requires_shipping' => true,
            'description' => $description,
            'qty' => 1,
            'unit_price' => 299,
            'gst_percentage' => 18,
            'taxable_value' => 253.39,
            'tax_total' => 45.61,
            'line_total' => 299,
        ]);

        return $commerce->fresh('items');
    }
}
