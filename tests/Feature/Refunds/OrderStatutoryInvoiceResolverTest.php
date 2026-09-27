<?php

namespace Tests\Feature\Refunds;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Refunds\OrderStatutoryInvoiceResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\TestCase;

class OrderStatutoryInvoiceResolverTest extends TestCase
{
    use CreatesStatutoryInvoicesForEinvoice;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_commerce_order_maps_to_statutory_invoice_via_source_id(): void
    {
        $user = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-STAT-1001',
            'serial_number' => 'SN-1001',
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $invoice = $this->makeTaxInvoice([
            'support_order_id' => null,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => 'RD-STAT-1001',
            'source_order_id' => 'WEB-1001',
        ]);

        $resolution = app(OrderStatutoryInvoiceResolver::class)->resolveForOrder($order);

        $this->assertSame($invoice->id, $resolution->invoice?->id);
        $this->assertNull($resolution->skipReason);
    }

    public function test_service_order_maps_via_support_order_id(): void
    {
        $user = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-STAT-2001',
            'serial_number' => 'SN-2001',
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $invoice = $this->makeTaxInvoice([
            'support_order_id' => $order->id,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => 'RD-STAT-2001',
        ]);

        $resolution = app(OrderStatutoryInvoiceResolver::class)->resolveForOrder($order);

        $this->assertSame($invoice->id, $resolution->invoice?->id);
    }

    public function test_no_invoice_returns_explicit_skip_reason(): void
    {
        $user = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-STAT-3001',
            'serial_number' => 'SN-3001',
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $resolution = app(OrderStatutoryInvoiceResolver::class)->resolveForOrder($order);

        $this->assertNull($resolution->invoice);
        $this->assertSame('no_linked_statutory_invoice', $resolution->skipReason);
    }

    public function test_pos_statutory_invoice_is_out_of_scope(): void
    {
        $user = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-STAT-4001',
            'serial_number' => 'SN-4001',
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $this->makeTaxInvoice([
            'support_order_id' => $order->id,
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'source_type' => StatutoryInvoiceSourceType::InventorySale,
            'source_id' => 'POS-4001',
        ]);

        $resolution = app(OrderStatutoryInvoiceResolver::class)->resolveForOrder($order);

        $this->assertNull($resolution->invoice);
        $this->assertSame('pos_statutory_boundary', $resolution->skipReason);
    }

    public function test_cancelled_invoice_is_still_resolved_for_downstream_eligibility(): void
    {
        $user = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-STAT-5001',
            'serial_number' => 'SN-5001',
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $invoice = $this->makeTaxInvoice([
            'support_order_id' => $order->id,
            'status' => StatutoryInvoiceStatus::Cancelled,
            'cancelled_at' => now(),
            'cancel_reason' => 'Already cancelled',
        ]);

        $resolution = app(OrderStatutoryInvoiceResolver::class)->resolveForOrder($order);

        $this->assertSame($invoice->id, $resolution->invoice?->id);
    }
}
