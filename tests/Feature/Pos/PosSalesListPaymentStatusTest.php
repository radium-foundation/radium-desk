<?php

namespace Tests\Feature\Pos;

use App\Enums\StatutoryInvoiceStatus;
use App\Models\CustomerPayment;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\InventoryUserBranch;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\ServicePos\ServicePaymentService;
use App\Services\StatutoryInvoice\StatutoryInvoicePaymentReadService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Support\Finance\FinanceAccess;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PosSalesListPaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $hardwareViewer;

    private InventoryBranch $branch;

    private PosSaleService $sales;

    private StatutoryInvoiceService $invoices;

    private ServicePaymentService $payments;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->hardwareViewer = User::factory()->create(['is_active' => true]);
        $this->hardwareViewer->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);
        InventoryUserBranch::query()->create([
            'user_id' => $this->hardwareViewer->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->sales = app(PosSaleService::class);
        $this->invoices = app(StatutoryInvoiceService::class);
        $this->payments = app(ServicePaymentService::class);
    }

    public function test_sales_list_shows_unpaid_when_no_successful_allocations_exist(): void
    {
        $invoice = $this->issuePendingInvoice('LIST-UNPAID-1');

        $this->actingAs($this->seller)
            ->get(route('pos.sales.index'))
            ->assertOk()
            ->assertSee('badge bg-warning', false)
            ->assertSee('Unpaid', false)
            ->assertSee($invoice->inventorySale?->sale_no, false);
    }

    public function test_sales_list_shows_paid_when_allocations_cover_invoice_value(): void
    {
        $invoice = $this->setInvoiceValue($this->issuePendingInvoice('LIST-PAID-1'), 118.00);
        $customer = $invoice->inventorySale?->customer;
        $this->assertNotNull($customer);

        $this->recordPayment($customer, $invoice, 118.00, 'CASH', 'CASH-LIST-1');

        $this->actingAs($this->seller)
            ->get(route('pos.sales.index'))
            ->assertOk()
            ->assertSee('badge bg-success', false)
            ->assertSee('Paid', false);
    }

    public function test_sales_list_shows_partially_paid_for_partial_allocations(): void
    {
        $invoice = $this->setInvoiceValue($this->issuePendingInvoice('LIST-PARTIAL-1'), 1000.00);
        $customer = $invoice->inventorySale?->customer;
        $this->assertNotNull($customer);

        $this->recordPayment($customer, $invoice, 400.00, 'HDFC D', 'UTR-PARTIAL-1');

        $this->actingAs($this->seller)
            ->get(route('pos.sales.index'))
            ->assertOk()
            ->assertSee('badge bg-info', false)
            ->assertSee('Partially paid', false);
    }

    public function test_unallocated_customer_payment_does_not_count_as_paid(): void
    {
        $invoice = $this->setInvoiceValue($this->issuePendingInvoice('LIST-NO-ALLOC-1'), 500.00);
        $customer = $invoice->inventorySale?->customer;
        $this->assertNotNull($customer);

        $this->payments->recordPayment(
            customer: $customer,
            amount: 500.00,
            method: 'CASH',
            paymentDate: now(),
            actor: $this->seller,
            reference: 'UNALLOCATED-ONLY',
        );

        $this->assertSame(1, CustomerPayment::query()->count());
        $summary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice->fresh());
        $this->assertSame(0.0, $summary->amountReceived);

        $this->actingAs($this->seller)
            ->get(route('pos.sales.index'))
            ->assertOk()
            ->assertSee('Unpaid', false);
    }

    public function test_rounding_boundary_treats_near_full_allocation_as_paid(): void
    {
        $read = app(StatutoryInvoicePaymentReadService::class);
        $this->assertSame(
            \App\Enums\StatutoryInvoicePaymentStatus::Paid,
            $read->allocationBackedPaymentStatus(100.00, 99.999),
        );
        $this->assertSame(
            \App\Enums\StatutoryInvoicePaymentStatus::PartiallyPaid,
            $read->allocationBackedPaymentStatus(100.00, 99.99),
        );
    }

    public function test_cancelled_statutory_invoice_shows_cancelled_payment_label(): void
    {
        $invoice = $this->issuePendingInvoice('LIST-CANCEL-1');
        DB::table('statutory_invoices')->where('id', $invoice->id)->update([
            'status' => StatutoryInvoiceStatus::Cancelled->value,
        ]);

        $this->actingAs($this->seller)
            ->get(route('pos.sales.index'))
            ->assertOk()
            ->assertSee('Invoice cancelled', false)
            ->assertSee('badge bg-secondary', false);
    }

    public function test_hardware_user_without_finance_access_sees_payment_badges_not_finance_amounts(): void
    {
        $this->issuePendingInvoice('LIST-HW-VIEW-1');
        $this->assertFalse(FinanceAccess::allowsInvoices($this->hardwareViewer));

        $this->actingAs($this->hardwareViewer)
            ->get(route('pos.sales.index'))
            ->assertOk()
            ->assertSee('Unpaid', false)
            ->assertDontSee('Received ₹', false)
            ->assertDontSee('Outstanding ₹', false)
            ->assertDontSee(route('finance.invoices.index'), false);
    }

    public function test_allocated_totals_for_invoices_uses_single_aggregate_query(): void
    {
        $first = $this->setInvoiceValue($this->issuePendingInvoice('LIST-BATCH-1'), 100.00);
        $second = $this->setInvoiceValue($this->issuePendingInvoice('LIST-BATCH-2'), 200.00);
        $customer = $first->inventorySale?->customer;
        $this->assertNotNull($customer);

        $this->recordPayment($customer, $first, 100.00, 'CASH', 'BATCH-1');
        $this->recordPayment($customer, $second, 50.00, 'CASH', 'BATCH-2');

        DB::enableQueryLog();
        $totals = app(StatutoryInvoicePaymentReadService::class)->allocatedTotalsForInvoices([
            (int) $first->id,
            (int) $second->id,
        ]);
        $allocationQueries = collect(DB::getQueryLog())
            ->filter(fn (array $entry): bool => str_contains(strtolower((string) $entry['query']), 'payment_allocations'))
            ->count();
        DB::disableQueryLog();

        $this->assertSame(1, $allocationQueries);
        $this->assertSame(100.00, $totals[(int) $first->id]);
        $this->assertSame(50.00, $totals[(int) $second->id]);
    }

    private function issuePendingInvoice(string $marker): StatutoryInvoice
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'LIST-'.$marker,
            'name' => 'List payment product '.$marker,
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($product, $this->branch, ['SN-'.$marker], $this->seller);

        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Buyer '.$marker, 'phone' => '9100'.substr(preg_replace('/\D/', '', $marker), 0, 6)],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['SN-'.$marker],
            ]],
            paymentMethod: '',
            actor: $this->seller,
            statutory: ['place_of_supply_state' => 'Delhi'],
            paymentReceived: false,
        );

        return $this->invoices->issueFromPosSale($sale, $this->seller);
    }

    private function setInvoiceValue(StatutoryInvoice $invoice, float $value): StatutoryInvoice
    {
        DB::table('statutory_invoices')->where('id', $invoice->id)->update([
            'invoice_value' => $value,
        ]);

        return $invoice->fresh(['inventorySale.customer']) ?? $invoice;
    }

    private function recordPayment(
        InventoryCustomer $customer,
        StatutoryInvoice $invoice,
        float $amount,
        string $method,
        ?string $reference = null,
    ): void {
        $payment = $this->payments->recordPayment(
            customer: $customer,
            amount: $amount,
            method: $method,
            paymentDate: now(),
            actor: $this->seller,
            reference: $reference,
        );

        $this->payments->allocatePayment(
            payment: $payment,
            invoice: $invoice,
            amount: $amount,
            actor: $this->seller,
        );
    }
}
