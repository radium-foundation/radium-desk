<?php

namespace Tests\Feature\Pos;

use App\Mail\StatutoryInvoiceMail;
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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PosSaleStatutoryInvoiceActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private InventoryBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        config([
            'mail.enabled' => true,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
        ]);

        $this->configureLocationSellerIdentity();
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

    public function test_pos_sale_show_includes_statutory_invoice_actions_after_completion(): void
    {
        $sale = $this->completeSale();

        $this->actingAs($this->seller)
            ->get(route('pos.sales.show', $sale))
            ->assertOk()
            ->assertSee('GST tax invoice', false)
            ->assertSee($sale->statutoryInvoice->invoice_number, false)
            ->assertSee('View invoice', false)
            ->assertSee('Download PDF', false)
            ->assertSee('Share', false);
    }

    public function test_pos_sale_statutory_pdf_download_does_not_create_a_second_invoice(): void
    {
        $sale = $this->completeSale();
        $invoiceId = $sale->statutory_invoice_id;

        $this->actingAs($this->seller)
            ->get(route('pos.sales.statutory.download', $sale))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame($invoiceId, $sale->fresh()->statutory_invoice_id);
    }

    public function test_pos_sale_statutory_email_sends_pdf_without_creating_another_invoice(): void
    {
        $sale = $this->completeSale();
        $invoiceId = $sale->statutory_invoice_id;

        $this->actingAs($this->seller)
            ->postJson(route('pos.sales.statutory.email', $sale), [
                'email' => 'walkin-buyer@example.com',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        Mail::assertSent(StatutoryInvoiceMail::class);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame($invoiceId, $sale->fresh()->statutory_invoice_id);
    }

    private function completeSale(): InventorySale
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'POS-ACTION-1',
            'name' => 'Mantra MFS 110 - 1R 1W U - 1 Q',
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
                'name' => 'Walk-in Buyer',
                'phone' => '9000001234',
                'email' => 'walkin-buyer@example.com',
            ],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
            ]],
            paymentMethod: 'Cash',
            actor: $this->seller,
            idempotencyKey: 'pos-action-sale-1',
        );

        return $sale->fresh(['statutoryInvoice', 'customer', 'branch']);
    }
}
