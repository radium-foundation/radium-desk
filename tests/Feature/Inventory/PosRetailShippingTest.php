<?php

namespace Tests\Feature\Inventory;

use App\Enums\CommerceOrderStatus;
use App\Enums\InventorySerialStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\CommerceOrder;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\InventorySerial;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PosRetailShippingTest extends TestCase
{
    use RefreshDatabase;

    private PosSaleService $sales;

    private InventoryStockService $stock;

    private StatutoryInvoiceService $invoices;

    private User $actor;

    private InventoryBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->sales = app(PosSaleService::class);
        $this->stock = app(InventoryStockService::class);
        $this->invoices = app(StatutoryInvoiceService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);

        $this->configureLocationSellerIdentity();
        config([
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
        ]);
        Carbon::setTestNow('2026-09-10 12:00:00');
    }

    public function test_pos_hardware_with_shipping_reaches_statutory_invoice_and_reconciles(): void
    {
        $sale = $this->completeSale(shippingAmount: 50.0);

        $this->assertSame('50.00', (string) $sale->shipping_amount);
        $this->assertSame('27.00', (string) $sale->tax);
        $this->assertSame('177.00', (string) $sale->total);

        $invoice = $this->invoices->issueFromPosSale($sale, $this->actor);

        $this->assertSame('50.00', (string) $invoice->shipping_amount);
        $this->assertSame('100.00', (string) $invoice->taxable_value);
        $this->assertSame('27.00', (string) $invoice->tax_total);
        $this->assertSame('177.00', (string) $invoice->invoice_value);
        $this->assertSame(13.5, (float) $invoice->cgst);
        $this->assertSame(13.5, (float) $invoice->sgst);
    }

    public function test_pos_hardware_without_shipping_remains_unchanged(): void
    {
        $withShipping = $this->completeSale(shippingAmount: 0.0);
        $baselineTotal = (string) $withShipping->total;

        $sale = $this->completeSale(shippingAmount: 0.0, serial: 'SHIP-BASE-2');

        $this->assertSame('0.00', (string) $sale->shipping_amount);
        $this->assertSame('18.00', (string) $sale->tax);
        $this->assertSame($baselineTotal, (string) $sale->total);

        $invoice = $this->invoices->issueFromPosSale($sale, $this->actor);

        $this->assertSame('0.00', (string) $invoice->shipping_amount);
        $this->assertSame('118.00', (string) $invoice->invoice_value);
    }

    public function test_commerce_service_does_not_propagate_shipping(): void
    {
        $invoice = $this->invoices->issueFromCommerceOrder($this->commerceOrder(
            sourceId: 'SVC-SHIP-1',
            hsn: '998313',
            channel: StatutoryInvoiceChannel::RdServiceNet,
            billingState: 'Delhi',
        ), $this->actor);

        $this->assertSame('0.00', (string) $invoice->shipping_amount);
    }

    public function test_commerce_hardware_line_kind_does_not_propagate_shipping(): void
    {
        $order = $this->commerceOrder(
            sourceId: 'HW-SHIP-1',
            hsn: '84716050',
            channel: StatutoryInvoiceChannel::RadiumBoxCom,
            billingState: 'Delhi',
            branchCode: 'MUMBAI',
        );
        $order->items()->first()?->update([
            'shipping_line_kind' => 'physical_merchandise',
            'requires_shipping' => true,
        ]);

        $invoice = $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);

        $this->assertSame('0.00', (string) $invoice->shipping_amount);
    }

    public function test_multi_line_pos_shipping_mints_once_on_statutory_invoice(): void
    {
        $productA = $this->product('MULTI-A', 100, serial: 'MULTI-A-1');
        $productB = $this->product('MULTI-B', 200, serial: 'MULTI-B-1');
        $this->stock->stockInSerialized($productA, $this->branch, ['MULTI-A-1'], $this->actor);
        $this->stock->stockInSerialized($productB, $this->branch, ['MULTI-B-1'], $this->actor);

        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Walk-in', 'phone' => '9000000011'],
            lines: [
                ['product_id' => $productA->id, 'qty' => 1, 'serials' => ['MULTI-A-1']],
                ['product_id' => $productB->id, 'qty' => 1, 'serials' => ['MULTI-B-1']],
            ],
            paymentMethod: 'Cash',
            actor: $this->actor,
            shippingAmount: 25.0,
            statutory: ['place_of_supply_state' => 'Delhi'],
        );

        $invoice = $this->invoices->issueFromPosSale($sale, $this->actor);

        $this->assertSame('25.00', (string) $invoice->shipping_amount);
        $this->assertSame('300.00', (string) $invoice->taxable_value);
        $this->assertCount(2, $invoice->items);
        $this->assertSame('384.00', (string) $invoice->invoice_value);
    }

    public function test_cancelled_pos_invoice_retains_shipping_snapshot(): void
    {
        $sale = $this->completeSale(shippingAmount: 40.0, serial: 'SHIP-CANCEL-1');
        $invoice = $this->invoices->issueFromPosSale($sale, $this->actor);

        $cancelled = $this->invoices->cancel($invoice, $this->actor, 'Customer return');

        $this->assertSame(StatutoryInvoiceStatus::Cancelled, $cancelled->status);
        $this->assertSame('40.00', (string) $cancelled->shipping_amount);
        $this->assertSame('40.00', (string) $sale->fresh()->shipping_amount);
    }

    public function test_shipping_does_not_change_stock_movement(): void
    {
        $sale = $this->completeSale(shippingAmount: 75.0, serial: 'STOCK-SHIP-1');
        $product = $sale->lines->first()?->product;

        $this->assertNotNull($product);
        $this->assertSame(InventorySerialStatus::Sold, InventorySerial::query()->where('serial_number', 'STOCK-SHIP-1')->value('status'));
        $this->assertSame(0, (int) $product->balances()->where('branch_id', $this->branch->id)->value('available_qty'));
    }

    public function test_mixed_gst_cart_uses_max_rate_for_shipping_tax(): void
    {
        $lowGst = $this->product('LOW-GST', 100, gst: 5, serial: 'LOW-GST-1');
        $highGst = $this->product('HIGH-GST', 100, gst: 18, serial: 'HIGH-GST-1');
        $this->stock->stockInSerialized($lowGst, $this->branch, ['LOW-GST-1'], $this->actor);
        $this->stock->stockInSerialized($highGst, $this->branch, ['HIGH-GST-1'], $this->actor);

        $quote = $this->sales->quoteTotals([
            ['product_id' => $lowGst->id, 'qty' => 1, 'serials' => ['LOW-GST-1']],
            ['product_id' => $highGst->id, 'qty' => 1, 'serials' => ['HIGH-GST-1']],
        ], shippingAmount: 100.0);

        $this->assertSame(100.0, $quote['shipping_amount']);
        $this->assertSame(41.0, $quote['tax']);
    }

    private function completeSale(float $shippingAmount, string $serial = 'SHIP-SERIAL-1'): InventorySale
    {
        $product = $this->product($serial.'-SKU', 100, serial: $serial);
        $this->stock->stockInSerialized($product, $this->branch, [$serial], $this->actor);

        return $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Walk-in', 'phone' => '9000000099'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => [$serial],
            ]],
            paymentMethod: 'Cash',
            actor: $this->actor,
            shippingAmount: $shippingAmount,
            statutory: ['place_of_supply_state' => 'Delhi'],
        );
    }

    private function product(string $sku, float $price, float $gst = 18, ?string $serial = null): InventoryProduct
    {
        return InventoryProduct::query()->create([
            'sku' => $sku,
            'name' => $sku,
            'hsn_code' => '84716050',
            'gst_percentage' => $gst,
            'unit_price' => $price,
            'is_serialized' => $serial !== null,
            'is_active' => true,
        ]);
    }

    private function commerceOrder(
        string $sourceId,
        string $hsn,
        StatutoryInvoiceChannel $channel,
        ?string $billingState = 'Delhi',
        ?string $branchCode = 'DELHI-RETAIL',
    ): CommerceOrder {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => $channel,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'idempotency_key' => 'statutory:'.$channel->value.':commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Customer',
            'billing_state' => $billingState,
            'branch_code' => $branchCode,
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100,
            'tax_total' => 18,
            'order_value' => 118,
            'ordered_at' => '2026-09-10 10:00:00',
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'description' => 'Line',
            'hsn_sac' => $hsn,
            'qty' => 1,
            'unit_price' => 100,
            'gst_percentage' => 18,
            'taxable_value' => 100,
            'tax_total' => 18,
            'line_total' => 118,
        ]);

        return $order->fresh(['items']);
    }
}
