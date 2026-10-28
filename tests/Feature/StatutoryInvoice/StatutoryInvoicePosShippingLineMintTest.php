<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\EInvoiceStoredGstGuard;
use App\Services\StatutoryInvoice\PosRetailShippingStatutoryLine;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StatutoryInvoicePosShippingLineMintTest extends TestCase
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
            'statutory_invoices.pos_retail_shipping.hsn_sac' => 'TEST-SHIPPING-HSN-NOT-FOR-PRODUCTION',
            'statutory_invoices.pos_retail_shipping.uqc' => 'NOS',
        ]);
        Carbon::setTestNow('2026-10-26 12:00:00');
    }

    public function test_inter_state_pos_shipping_persists_line_and_irn_payload_reconciles(): void
    {
        $product = $this->product('INT-SHIP-1', 10381.36, serial: 'INT-SHIP-SN-1');
        $this->stock->stockInSerialized($product, $this->branch, ['INT-SHIP-SN-1'], $this->actor);

        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'B2B Buyer', 'phone' => '9000000101', 'gstin' => '09AAAAA0000A1Z5'],
            lines: [['product_id' => $product->id, 'qty' => 1, 'serials' => ['INT-SHIP-SN-1']]],
            paymentMethod: 'Cash',
            actor: $this->actor,
            shippingAmount: 1500.0,
            statutory: [
                'place_of_supply_state' => 'Uttar Pradesh',
                'buyer_gstin' => '09AAAAA0000A1Z5',
                'billing_city' => 'Noida',
                'billing_state' => 'Uttar Pradesh',
                'billing_pincode' => '201301',
            ],
        );

        $invoice = $this->invoices->issueFromPosSale($sale, $this->actor);

        $this->assertSame('1500.00', (string) $invoice->shipping_amount);
        $this->assertCount(2, $invoice->items);
        $shipping = $invoice->items->firstWhere('sku', PosRetailShippingStatutoryLine::LINE_SKU);
        $this->assertNotNull($shipping);
        $this->assertSame('270.00', (string) $shipping->igst);
        $this->assertSame('0.00', (string) $invoice->cgst);
        $this->assertSame([], EInvoiceStoredGstGuard::missingReasons($invoice));
        $this->assertSame(
            (int) round((float) $invoice->taxable_value * 100)
            + (int) round((float) $invoice->shipping_amount * 100)
            + (int) round((float) $invoice->tax_total * 100)
            + (int) round((float) $invoice->rounding * 100),
            (int) round((float) $invoice->invoice_value * 100),
        );

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice->fresh(['items']));
        $this->assertCount(2, $payload->items);
        $this->assertNotContains('line_header_gst_mismatch', $payload->gaps);
        $lineTaxableSum = array_sum(array_map(
            fn (array $row) => (float) ($row['taxable_value'] ?? 0),
            $payload->items,
        ));
        $this->assertSame($payload->values['taxable_value'], number_format($lineTaxableSum, 2, '.', ''));
    }

    private function product(string $sku, float $price, ?string $serial = null): InventoryProduct
    {
        return InventoryProduct::query()->create([
            'sku' => $sku,
            'name' => $sku,
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => $price,
            'is_serialized' => $serial !== null,
            'is_active' => true,
        ]);
    }
}
