<?php

namespace Tests\Feature\Dashboard;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareWorkspaceFilter;
use App\Enums\HardwareWorkspaceScope;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\InventoryProduct;
use App\Models\Order;
use App\Models\User;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentGapDiagnostics;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkQueue;
use App\Services\HardwareFulfilment\HardwareNeedsActionSqlQuery;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HardwareDashboardSerialGapVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->withoutVite();
        $this->seedCatalog();
    }

    public function test_paid_hardware_with_support_serial_and_no_fulfilment_is_searchable(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->hardwareSupportOrderWithoutFulfilment('RBP2138', '11119634');

        $from = Carbon::parse('2026-11-14 00:00:00', HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $to = Carbon::parse('2026-11-15 00:00:00', HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $queue = app(HardwareFulfilmentWorkQueue::class);
        $search = $queue->dashboard($from, $to, 'RBP2138', HardwareWorkspaceScope::Active, HardwareWorkspaceFilter::NeedsAction);

        $this->assertSame(1, $search['unfiltered_total']);
        $this->assertSame(['RBP2138'], $search['rows']->pluck('sourceId')->all());
        $this->assertSame('Hardware fulfilment not opened', $search['rows']->first()?->statusLabel);

        $page = app(HardwareNeedsActionSqlQuery::class)->searchActivePage($from, $to, 'RBP2138', 1, 40);
        $this->assertSame(1, $page['total']);
        $this->assertSame([(int) Order::query()->where('order_id', 'RBP2138')->value('id')], $page['order_ids']);

        $this->actingAs($admin)
            ->get(route('dashboard', ['workspace' => 'hardware', 'q' => 'RBP2138']))
            ->assertOk()
            ->assertSee('RBP2138');
    }

    public function test_rde_serial_gap_matches_rbp_pattern(): void
    {
        $this->hardwareSupportOrderWithoutFulfilment('RDE990001', 'SN-990001');

        $from = Carbon::parse('2026-11-14 00:00:00', HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $to = Carbon::parse('2026-11-15 00:00:00', HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);

        $this->assertSame(1, HardwareFulfilmentGapDiagnostics::count($from, $to));
        $this->assertSame(['RDE990001'], HardwareFulfilmentGapDiagnostics::sampleSourceIds($from, $to));
    }

    public function test_service_only_order_without_hardware_commerce_is_not_in_gap_cohort(): void
    {
        $creator = User::factory()->create(['is_active' => true]);
        Order::query()->create([
            'order_id' => 'RB990002',
            'product_name' => 'Remote support',
            'status' => 'active',
            'cashfree_payment_id' => 'cf_rb990002',
            'serial_number' => 'SN-SERVICE',
            'created_by' => $creator->id,
        ]);

        $from = Carbon::parse('2026-11-14 00:00:00', HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $to = Carbon::parse('2026-11-15 00:00:00', HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);

        $this->assertSame(0, HardwareFulfilmentGapDiagnostics::count($from, $to));
    }

    public function test_paid_hardware_with_existing_fulfilment_is_not_duplicated_in_gap_search(): void
    {
        $support = $this->hardwareSupportOrderWithoutFulfilment('RBP990003', 'SN-990003');
        $commerce = CommerceOrder::query()->where('source_id', 'RBP990003')->firstOrFail();

        \App\Models\HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RBP990003',
            'idempotency_key' => 'hf:RBP990003',
            'state' => \App\Enums\HardwareFulfilmentState::Ingested,
            'support_order_id' => $support->id,
        ]);

        $from = Carbon::parse('2026-11-14 00:00:00', HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $to = Carbon::parse('2026-11-15 00:00:00', HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);

        $this->assertSame(0, HardwareFulfilmentGapDiagnostics::count($from, $to));
    }

    private function hardwareSupportOrderWithoutFulfilment(string $sourceId, string $serial): Order
    {
        $creator = User::factory()->create(['is_active' => true]);
        $order = Order::query()->create([
            'order_id' => $sourceId,
            'product_name' => 'MFS 110',
            'status' => 'active',
            'customer_name' => 'Buyer',
            'cashfree_payment_id' => 'cf_'.$sourceId,
            'serial_number' => $serial,
            'created_by' => $creator->id,
        ]);
        $order->forceFill(['created_at' => '2026-11-14 12:06:13'])->save();

        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.substr($sourceId, -6),
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::Validated,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Buyer',
            'received_at' => now(),
            'ordered_at' => '2026-11-14 12:03:29',
        ]);
        CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'sku' => '946',
            'catalog_sku' => 'RBMFS110L1',
            'model_id' => 946,
            'shipping_line_kind' => HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
            'requires_shipping' => true,
            'description' => 'MFS 110',
            'qty' => 1,
            'unit_price' => 2499,
            'gst_percentage' => 18,
            'taxable_value' => 2117.8,
            'tax_total' => 381.2,
            'line_total' => 2499,
        ]);

        return $order->fresh();
    }

    private function seedCatalog(): void
    {
        $product = InventoryProduct::query()->create([
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
            'inventory_product_id' => $product->id,
            'catalog_sku' => 'RBMFS110L1',
            'channel_sku' => '946',
        ]);
    }
}