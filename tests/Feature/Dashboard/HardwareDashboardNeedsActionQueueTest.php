<?php

namespace Tests\Feature\Dashboard;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\HardwareWorkspaceFilter;
use App\Enums\HardwareWorkspaceScope;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryProduct;
use App\Models\Order;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkQueue;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HardwareDashboardNeedsActionQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->withoutVite();
    }

    public function test_default_hardware_view_is_needs_action_and_skips_ingested_inspect(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->seedCatalog();

        $this->fulfilment('RDE980001', HardwareFulfilmentState::Ingested);
        $this->fulfilment('RDE980002', HardwareFulfilmentState::Ingested);
        $awaiting = $this->fulfilment('RDE980003', HardwareFulfilmentState::ReadyForFulfilment);
        $this->fulfilment('RDE980004', HardwareFulfilmentState::InvoiceIssued, serial: true, invoice: true);

        $html = $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'hardware']))
            ->assertOk()
            ->assertSee('Needs Action')
            ->assertSee('Product Mapping Required')
            ->assertSee('Awaiting Serial')
            ->assertSee('AWB Pending')
            ->assertSee('Package Photo Pending')
            ->assertSee('Shipping')
            ->assertSee('Completed')
            ->assertSee('All')
            ->assertSee('RDE980003')
            ->assertDontSee('RDE980001')
            ->assertDontSee('Out for Delivery')
            ->getContent();

        $this->assertStringContainsString('data-hardware-filter="needs_action"', $html);
        $this->assertStringContainsString('data-live-hardware-url="', $html);
        $this->assertStringContainsString('Allocate Serial', $html);
        $this->assertSame(1, preg_match_all('/\sdata-hardware-select(\s|>)/', $html));
        $this->assertMatchesRegularExpression('/data-hardware-filter-count="awaiting_serial">\(1\)/', $html);

        $inspected = (int) (preg_match('/data-hardware-inspected-count="(\d+)"/', $html, $match) ? $match[1] : -1);
        $this->assertLessThan(4, $inspected);
        $this->assertGreaterThan(0, $inspected);

        $queue = app(HardwareFulfilmentWorkQueue::class);
        $from = HardwareFulfilmentEligibility::cutoffInstant();
        $to = Carbon::now(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $needsAction = $queue->dashboard($from, $to, '', HardwareWorkspaceScope::Active, HardwareWorkspaceFilter::NeedsAction);
        $this->assertSame(1, $needsAction['unfiltered_total']);
        $this->assertSame(2, $queue->lastInspectedFulfilmentCount);
        $this->assertSame(1, $needsAction['filter_counts']['awaiting_serial']);
        $this->assertSame(1, $needsAction['filter_counts']['needs_action']);

        $all = $queue->dashboard($from, $to, '', HardwareWorkspaceScope::Active, HardwareWorkspaceFilter::All);
        $this->assertSame(4, $all['unfiltered_total']);
        $this->assertSame(4, $queue->lastInspectedFulfilmentCount);

        $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'hardware', 'hw_filter' => 'all']))
            ->assertOk()
            ->assertSee('RDE980001')
            ->assertSee('RDE980003')
            ->assertSee('RDE980004');

        $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'hardware', 'hw_scope' => 'shipped']))
            ->assertOk()
            ->assertSee('Completed')
            ->assertSee('Delivered');

        $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'hardware', 'hw_filter' => 'shipping']))
            ->assertOk()
            ->assertSee('Out for Pickup')
            ->assertSee('Ready for Pickup')
            ->assertSee('In Transit')
            ->assertSee('Picked Up')
            ->assertDontSee('Out for Delivery');

        $this->actingAs($admin)
            ->getJson(route('dashboard.live.hardware', [
                'ids' => [$awaiting->id],
                'hw_filter' => 'needs_action',
            ]))
            ->assertOk()
            ->assertJsonPath('rows.0.fulfilment_id', $awaiting->id)
            ->assertJsonPath('rows.0.filter', 'awaiting_serial');

        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $awaiting->fresh()->state);
    }

    public function test_needs_action_paginates_without_rendering_the_full_queue(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->seedCatalog();

        for ($i = 1; $i <= 45; $i++) {
            $this->fulfilment(sprintf('RDE981%03d', $i), HardwareFulfilmentState::ReadyForFulfilment);
        }

        $first = $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'hardware']))
            ->assertOk()
            ->assertSee('Page 1 of 2 (45)')
            ->getContent();
        $this->assertSame(40, preg_match_all('/\sdata-hardware-select(\s|>)/', $first));

        $second = $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'hardware', 'hw_page' => 2]))
            ->assertOk()
            ->getContent();
        $this->assertSame(5, preg_match_all('/\sdata-hardware-select(\s|>)/', $second));
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

    private function fulfilment(
        string $sourceId,
        HardwareFulfilmentState $state,
        bool $serial = false,
        bool $invoice = false,
    ): HardwareFulfilment {
        $creator = User::factory()->create(['is_active' => true]);
        $order = Order::query()->create([
            'order_id' => $sourceId,
            'product_name' => 'MFS 110',
            'status' => 'active',
            'customer_name' => 'Buyer '.$sourceId,
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
            'customer_name' => 'Buyer '.$sourceId,
            'received_at' => now(),
            'ordered_at' => '2026-09-07 04:30:00',
            'support_order_id' => $order->id,
            'statutory_invoice_id' => $invoiceModel?->id,
        ]);
        CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'sku' => 'RBMFS110L1',
            'catalog_sku' => 'MFS110',
            'model_id' => 946,
            'shipping_line_kind' => HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
            'requires_shipping' => true,
            'description' => 'MFS110',
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
                'line_no' => 1,
                'position' => 1,
                'serial_number' => 'SN-'.$sourceId,
                'status' => HardwareFulfilmentSerialStatus::Allocated,
                'allocated_at' => now(),
            ]);
        }

        return $fulfilment;
    }
}
