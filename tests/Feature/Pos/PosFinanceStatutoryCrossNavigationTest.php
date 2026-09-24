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
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PosFinanceStatutoryCrossNavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $financeViewer;

    private InventoryBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->withoutVite();
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

        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        InventoryUserBranch::query()->create([
            'user_id' => $this->admin->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->financeViewer = User::factory()->create(['is_active' => true]);
        $this->financeViewer->givePermissionTo([
            RolePermissionSeeder::PERMISSION_FINANCE_VIEW,
            RolePermissionSeeder::PERMISSION_FINANCE_INVOICES_VIEW,
        ]);
    }

    public function test_finance_invoice_show_links_to_related_pos_sale(): void
    {
        [$sale, $invoice] = $this->linkedPosSaleAndInvoice();

        $this->assertSame($sale->id, $invoice->inventory_sale_id);
        $this->assertSame($invoice->id, $sale->statutory_invoice_id);

        $posUrl = route('pos.sales.show', $sale);

        $this->actingAs($this->admin)
            ->get(route('finance.invoices.show', $invoice))
            ->assertOk()
            ->assertSee($sale->sale_no, false)
            ->assertSee('View POS Sale', false)
            ->assertSee($posUrl, false);
    }

    public function test_finance_invoice_without_pos_sale_shows_no_pos_navigation_link(): void
    {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-SVC-001',
            'idempotency_key' => 'service-order-invoice-nav-test',
            'document_type' => 'tax_invoice',
            'status' => 'issued',
            'channel' => 'desk_service',
            'source_type' => 'ServiceOrder',
            'source_id' => '1',
            'seller_gstin' => $this->configuredSellerGstin('delhi'),
            'seller_name' => 'Seller',
            'buyer_name' => 'Buyer',
            'taxable_value' => 100,
            'tax_total' => 18,
            'invoice_value' => 118,
            'issued_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('finance.invoices.show', $invoice))
            ->assertOk()
            ->assertDontSee('View POS Sale', false)
            ->assertDontSee('POS / sale reference', false);
    }

    public function test_pos_sale_show_links_to_related_finance_invoice(): void
    {
        [$sale, $invoice] = $this->linkedPosSaleAndInvoice();

        $financeUrl = route('finance.invoices.show', $invoice);

        $this->actingAs($this->admin)
            ->get(route('pos.sales.show', $sale))
            ->assertOk()
            ->assertSee('Statutory invoice:', false)
            ->assertSee($invoice->invoice_number, false)
            ->assertSee('View Invoice', false)
            ->assertSee($financeUrl, false);
    }

    public function test_pos_sale_without_statutory_invoice_shows_no_finance_navigation_link(): void
    {
        $sale = $this->completeSaleWithoutStatutoryInvoice();
        StatutoryInvoice::query()->where('inventory_sale_id', $sale->id)->delete();
        $sale->forceFill(['statutory_invoice_id' => null])->save();

        $this->actingAs($this->admin)
            ->get(route('pos.sales.show', $sale->fresh()))
            ->assertOk()
            ->assertSee('No statutory GST invoice', false)
            ->assertDontSee('View Invoice', false);
    }

    public function test_finance_viewer_without_pos_access_sees_sale_reference_without_pos_link(): void
    {
        [$sale, $invoice] = $this->linkedPosSaleAndInvoice();

        $this->actingAs($this->financeViewer)
            ->get(route('finance.invoices.show', $invoice))
            ->assertOk()
            ->assertSee($sale->sale_no, false)
            ->assertSee('POS / sale reference', false)
            ->assertDontSee('View POS Sale', false)
            ->assertDontSee(route('pos.sales.show', $sale), false);
    }

    public function test_pos_seller_without_finance_access_sees_statutory_number_without_finance_link(): void
    {
        [$sale, $invoice] = $this->linkedPosSaleAndInvoice();

        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        $seller->givePermissionTo(RolePermissionSeeder::PERMISSION_POS_VIEW);
        InventoryUserBranch::query()->create([
            'user_id' => $seller->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->actingAs($seller)
            ->get(route('pos.sales.show', $sale))
            ->assertOk()
            ->assertSee($invoice->invoice_number, false)
            ->assertDontSee(route('finance.invoices.show', $invoice), false);
    }

    /**
     * @return array{0: InventorySale, 1: StatutoryInvoice}
     */
    private function linkedPosSaleAndInvoice(): array
    {
        $sale = $this->completeSaleWithoutStatutoryInvoice();
        $invoice = app(StatutoryInvoiceService::class)->issueFromPosSale($sale, $this->admin);

        return [$sale->fresh(['statutoryInvoice']), $invoice->fresh()];
    }

    private function completeSaleWithoutStatutoryInvoice(): InventorySale
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'POS-NAV-1',
            'name' => 'Mantra MFS110 NAV',
            'hsn_code' => '84716050',
            'uqc' => 'NOS',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInQuantity($product, $this->branch, 5, $this->admin);

        return app(PosSaleService::class)->completeSale(
            branch: $this->branch,
            customer: [
                'name' => 'Navigation Buyer',
                'phone' => '9000005678',
                'email' => 'nav-buyer@example.com',
            ],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
            ]],
            paymentMethod: 'Bank Transfer',
            actor: $this->admin,
            idempotencyKey: 'pos-nav-sale-'.uniqid(),
        );
    }
}
