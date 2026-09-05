<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PosFinanceHubIssueTest extends TestCase
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

        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->sales = app(PosSaleService::class);
        $this->stock = app(InventoryStockService::class);
        $this->invoices = app(StatutoryInvoiceService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
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
    }

    public function test_pos_complete_does_not_mint_a_statutory_invoice(): void
    {
        $sale = $this->completeEligibleSale();

        $this->assertMatchesRegularExpression('/^INV-DELHI-RETAIL-\d{4}-\d{5}$/', (string) $sale->invoice_number);
        $this->assertNull($sale->statutory_invoice_id);
        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_finance_hub_issues_a_distinct_statutory_number_from_a_pos_sale(): void
    {
        $sale = $this->completeEligibleSale();

        $invoice = $this->invoices->issueFromPosSale($sale, $this->actor);

        $this->assertSame('INV-07671', $invoice->invoice_number);
        $this->assertSame($this->configuredSellerGstin('delhi'), $invoice->seller_gstin);
        $this->assertNotSame($sale->invoice_number, $invoice->invoice_number);
        $this->assertSame(StatutoryInvoiceChannel::DeskPos, $invoice->channel);
        $this->assertSame(StatutoryInvoiceSourceType::InventorySale->value, $invoice->source_type);
        $this->assertSame((string) $sale->id, $invoice->source_id);
        $this->assertSame($sale->id, $sale->fresh()->statutory_invoice_id);
        $this->assertSame($sale->invoice_number, $sale->fresh()->invoice_number);
    }

    public function test_pos_statutory_issue_is_idempotent(): void
    {
        $sale = $this->completeEligibleSale();

        $first = $this->invoices->issueFromPosSale($sale, $this->actor);
        $second = $this->invoices->issueFromPosSale($sale->fresh(), $this->actor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('INV-07671', $first->invoice_number);
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    public function test_cancel_keeps_the_pos_statutory_number(): void
    {
        $sale = $this->completeEligibleSale();
        $invoice = $this->invoices->issueFromPosSale($sale, $this->actor);

        $cancelled = $this->invoices->cancel($invoice, $this->actor, 'Test cancel');

        $this->assertSame(StatutoryInvoiceStatus::Cancelled, $cancelled->status);
        $this->assertSame('INV-07671', $cancelled->invoice_number);
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    public function test_pre_september_pos_sale_cannot_receive_a_statutory_number(): void
    {
        Carbon::setTestNow('2026-08-31 23:59:59');
        $sale = $this->completeEligibleSale();

        try {
            $this->invoices->issueFromPosSale($sale, $this->actor);
            $this->fail('Expected pre-2026-09-01 POS sale to refuse mint.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('2026-09-01', implode(' ', $exception->errors()['eligibility'] ?? []));
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertNull($sale->fresh()->statutory_invoice_id);
    }

    public function test_first_eligible_september_pos_sale_issues_inv_07671(): void
    {
        Carbon::setTestNow('2026-09-01 00:00:00');
        $sale = $this->completeEligibleSale();

        $invoice = $this->invoices->issueFromPosSale($sale, $this->actor);

        $this->assertSame('INV-07671', $invoice->invoice_number);
        $this->assertSame(1, $invoice->allocation?->seq_int);
    }

    public function test_issue_fails_closed_when_the_branch_is_not_delhi_or_mumbai(): void
    {
        $this->branch->update(['code' => 'HQ']);
        $sale = $this->completeEligibleSale();

        $this->expectException(ValidationException::class);
        $this->invoices->issueFromPosSale($sale, $this->actor);
    }

    public function test_admin_can_issue_from_the_finance_pending_queue(): void
    {
        $sale = $this->completeEligibleSale();

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.pending'))
            ->assertOk()
            ->assertSee($sale->sale_no)
            ->assertSee('Receipt '.$sale->invoice_number);

        $this->actingAs($this->actor)
            ->post(route('finance.invoices.sales.issue', $sale))
            ->assertRedirect();

        $invoice = StatutoryInvoice::query()->firstOrFail();
        $this->assertSame('INV-07671', $invoice->invoice_number);

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('INV-07671')
            ->assertSee($sale->invoice_number);

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.pdf', $invoice))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.index'))
            ->assertOk()
            ->assertSee('INV-07671')
            ->assertDontSee($sale->invoice_number, false);
    }

    private function completeEligibleSale(): InventorySale
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'MFS110-HUB',
            'name' => 'Mantra MFS110',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $this->stock->stockInSerialized($product, $this->branch, ['HUB-SERIAL-1'], $this->actor);

        return $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Walk-in', 'phone' => '9000000099'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['HUB-SERIAL-1'],
            ]],
            paymentMethod: 'Cash',
            actor: $this->actor,
            statutory: [
                'place_of_supply_state' => 'Delhi',
            ],
        );
    }
}
