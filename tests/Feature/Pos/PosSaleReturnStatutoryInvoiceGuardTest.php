<?php

namespace Tests\Feature\Pos;

use App\Enums\InventorySaleStatus;
use App\Enums\InventorySerialStatus;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\InventorySerial;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\DisablesRequestForgeryProtection;
use Tests\TestCase;

class PosSaleReturnStatutoryInvoiceGuardTest extends TestCase
{
    use DisablesRequestForgeryProtection;
    use RefreshDatabase;

    private User $admin;

    private InventoryBranch $branch;

    private PosSaleService $sales;

    private StatutoryInvoiceService $invoices;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->configureLocationSellerIdentity();
        $this->disableRequestForgeryProtection();

        config([
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
        ]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);

        $this->sales = app(PosSaleService::class);
        $this->invoices = app(StatutoryInvoiceService::class);
    }

    public function test_return_is_blocked_when_statutory_invoice_is_issued(): void
    {
        [$sale, $invoice, $serialNumber] = $this->completedSaleWithIssuedInvoice('RET-BLOCK-1');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cancel sale');

        try {
            $this->sales->returnSale($sale, $this->admin, 'Attempted return with issued invoice');
        } finally {
            $sale->refresh();
            $invoice->refresh();
            $serial = InventorySerial::query()->where('serial_number', $serialNumber)->firstOrFail();

            $this->assertSame(InventorySaleStatus::Completed, $sale->status);
            $this->assertSame(StatutoryInvoiceStatus::Issued, $invoice->status);
            $this->assertSame(InventorySerialStatus::Sold, $serial->status);
        }
    }

    public function test_return_http_is_blocked_when_statutory_invoice_is_issued(): void
    {
        [$sale, $invoice, $serialNumber] = $this->completedSaleWithIssuedInvoice('RET-BLOCK-HTTP-1');

        $this->actingAs($this->admin)
            ->post(route('pos.sales.return', $sale), ['reason' => 'Should not reverse stock'])
            ->assertSessionHasErrors('sale');

        $sale->refresh();
        $invoice->refresh();
        $serial = InventorySerial::query()->where('serial_number', $serialNumber)->firstOrFail();

        $this->assertSame(InventorySaleStatus::Completed, $sale->status);
        $this->assertSame(StatutoryInvoiceStatus::Issued, $invoice->status);
        $this->assertSame(InventorySerialStatus::Sold, $serial->status);
    }

    public function test_return_succeeds_when_no_statutory_invoice_exists(): void
    {
        config(['statutory_invoices.location_series.enabled' => false]);
        $sales = app(PosSaleService::class);

        $product = $this->stockSerializedProduct('RET-OK-NO-INV', 'SN-RET-NO-INV');
        $sale = $sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'No Invoice Buyer', 'phone' => '9100111222'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['SN-RET-NO-INV'],
            ]],
            paymentMethod: 'Cash',
            actor: $this->admin,
            statutory: ['place_of_supply_state' => 'Delhi'],
        );

        $this->assertNull($sale->statutory_invoice_id);
        $this->assertSame(0, StatutoryInvoice::query()->count());

        $returned = $sales->returnSale($sale, $this->admin, 'Customer return without GST invoice');

        $serial = InventorySerial::query()->where('serial_number', 'SN-RET-NO-INV')->firstOrFail();
        $this->assertSame(InventorySaleStatus::Returned, $returned->status);
        $this->assertSame(InventorySerialStatus::Available, $serial->status);
    }

    public function test_return_is_blocked_when_statutory_invoice_is_cancelled_but_sale_still_completed(): void
    {
        [$sale, $invoice, $serialNumber] = $this->completedSaleWithIssuedInvoice('RET-CANCELLED-INV', 'SN-RET-CXL');
        $invoice->update(['status' => StatutoryInvoiceStatus::Cancelled]);

        $this->expectException(ValidationException::class);

        try {
            $this->sales->returnSale($sale->fresh(), $this->admin, 'Return after invoice cancelled elsewhere');
        } finally {
            $sale->refresh();
            $serial = InventorySerial::query()->where('serial_number', $serialNumber)->firstOrFail();
            $this->assertSame(InventorySaleStatus::Completed, $sale->status);
            $this->assertSame(InventorySerialStatus::Sold, $serial->status);
        }
    }

    /**
     * @return array{0: InventorySale, 1: StatutoryInvoice, 2: string}
     */
    private function completedSaleWithIssuedInvoice(string $marker, ?string $serialNumber = null): array
    {
        $serialNumber ??= 'SN-'.$marker;
        $product = $this->stockSerializedProduct($marker, $serialNumber);

        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Buyer '.$marker, 'phone' => '9200'.substr(preg_replace('/\D/', '', $marker), 0, 6)],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => [$serialNumber],
            ]],
            paymentMethod: 'Cash',
            actor: $this->admin,
            statutory: ['place_of_supply_state' => 'Delhi'],
        );

        $invoice = $this->invoices->issueFromPosSale($sale, $this->admin);
        $this->assertSame(StatutoryInvoiceStatus::Issued, $invoice->status);

        return [$sale->fresh(['statutoryInvoice']), $invoice->fresh(), $serialNumber];
    }

    private function stockSerializedProduct(string $marker, string $serialNumber): InventoryProduct
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'SKU-'.$marker,
            'name' => 'Product '.$marker,
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($product, $this->branch, [$serialNumber], $this->admin);

        return $product;
    }
}
