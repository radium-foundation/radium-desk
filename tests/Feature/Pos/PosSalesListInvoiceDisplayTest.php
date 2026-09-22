<?php

namespace Tests\Feature\Pos;

use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\InventoryUserBranch;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosSalesListInvoiceDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private InventoryBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->configureLocationSellerIdentity();
        config([
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
        ]);

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);
        InventoryUserBranch::query()->create([
            'user_id' => $this->seller->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    public function test_sales_list_shows_authoritative_statutory_invoice_number_not_internal_receipt(): void
    {
        $first = $this->completeSale('pos-list-invoice-1', '9000001001', 'Buyer One');
        $second = $this->completeSale('pos-list-invoice-2', '9000001002', 'Buyer Two');

        $firstStatutory = $first->statutoryInvoice?->invoice_number;
        $secondStatutory = $second->statutoryInvoice?->invoice_number;

        $this->assertNotNull($firstStatutory);
        $this->assertNotNull($secondStatutory);
        $this->assertNotSame($first->invoice_number, $firstStatutory);
        $this->assertNotSame($second->invoice_number, $secondStatutory);
        $this->assertNotSame($first->sale_no, $firstStatutory);
        $this->assertNotSame($second->sale_no, $secondStatutory);

        $response = $this->actingAs($this->seller)
            ->get(route('pos.sales.index'));

        $response->assertOk()
            ->assertSee($first->sale_no, false)
            ->assertSee($second->sale_no, false)
            ->assertSee($firstStatutory, false)
            ->assertSee($secondStatutory, false)
            ->assertSee('Buyer One', false)
            ->assertSee('Buyer Two', false)
            ->assertSee('DELHI-RETAIL', false)
            ->assertSee('Completed', false)
            ->assertDontSee($first->invoice_number, false)
            ->assertDontSee($second->invoice_number, false);
    }

    public function test_sales_list_search_matches_statutory_invoice_number(): void
    {
        $sale = $this->completeSale('pos-list-invoice-search', '9000001003', 'Search Buyer');
        $statutoryNumber = $sale->statutoryInvoice?->invoice_number;

        $this->assertNotNull($statutoryNumber);

        $this->actingAs($this->seller)
            ->get(route('pos.sales.index', ['q' => $statutoryNumber]))
            ->assertOk()
            ->assertSee($sale->sale_no, false)
            ->assertSee($statutoryNumber, false)
            ->assertDontSee($sale->invoice_number, false);
    }

    public function test_sales_list_shows_dash_when_statutory_invoice_is_missing(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'POS-LIST-NO-INV',
            'name' => 'No statutory invoice product',
            'hsn_code' => '84716050',
            'uqc' => 'NOS',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInQuantity($product, $this->branch, 2, $this->seller);

        $sale = InventorySale::query()->create([
            'sale_no' => 'POS-NO-STAT',
            'invoice_number' => 'INV-DELHI-RETAIL-2026-00999',
            'branch_id' => $this->branch->id,
            'status' => \App\Enums\InventorySaleStatus::Completed,
            'subtotal' => 100,
            'discount' => 0,
            'tax' => 18,
            'total' => 118,
            'payment_method' => 'Cash',
            'created_by' => $this->seller->id,
            'completed_at' => now(),
        ]);

        $this->assertSame(0, StatutoryInvoice::query()->count());

        $this->actingAs($this->seller)
            ->get(route('pos.sales.index'))
            ->assertOk()
            ->assertSee('POS-NO-STAT', false)
            ->assertDontSee('INV-DELHI-RETAIL-2026-00999', false);
    }

    private function completeSale(string $idempotencyKey, string $phone, string $name): InventorySale
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'POS-LIST-'.$idempotencyKey,
            'name' => 'POS list invoice product',
            'hsn_code' => '84716050',
            'uqc' => 'NOS',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInQuantity($product, $this->branch, 5, $this->seller);

        $sale = app(PosSaleService::class)->completeSale(
            branch: $this->branch,
            customer: [
                'name' => $name,
                'phone' => $phone,
            ],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
            ]],
            paymentMethod: 'Cash',
            actor: $this->seller,
            idempotencyKey: $idempotencyKey,
        );

        return $sale->fresh(['statutoryInvoice', 'customer', 'branch']);
    }
}
