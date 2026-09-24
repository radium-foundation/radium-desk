<?php

namespace Tests\Feature\Pos;

use App\Enums\StatutoryInvoicePaymentStatus;
use App\Models\CustomerPayment;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\PaymentAllocation;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\ServicePos\ServicePaymentService;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use App\Services\StatutoryInvoice\StatutoryInvoicePaymentReadService;
use App\Services\StatutoryInvoice\StatutoryInvoicePaymentReconciliationService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Support\Inventory\PosSalePaymentState;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DisablesRequestForgeryProtection;
use Tests\TestCase;

class PosUnpaidSaleWorkflowTest extends TestCase
{
    use DisablesRequestForgeryProtection;
    use RefreshDatabase;

    private User $seller;

    private InventoryBranch $branch;

    private PosSaleService $sales;

    private StatutoryInvoiceService $invoices;

    private ServicePaymentService $payments;

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

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);

        $this->sales = app(PosSaleService::class);
        $this->invoices = app(StatutoryInvoiceService::class);
        $this->payments = app(ServicePaymentService::class);
    }

    public function test_paid_pos_sale_flow_remains_unchanged(): void
    {
        $sale = $this->completePaidSale('Cash', 'PAID-FLOW-1');

        $this->assertFalse(PosSalePaymentState::isPaymentPending($sale));
        $this->assertSame('Cash', $sale->payment_method);
        $this->assertNull($sale->payment_reference);
    }

    public function test_unpaid_pos_sale_can_be_created_without_canonical_payment_records(): void
    {
        $sale = $this->completePendingSale('Bank Transfer', 'PENDING-1');
        $invoice = $this->invoices->issueFromPosSale($sale, $this->seller);

        $this->assertTrue(PosSalePaymentState::isPaymentPending($sale));
        $this->assertSame('Bank Transfer', $sale->payment_method);
        $this->assertSame(PosSalePaymentState::PAYMENT_PENDING_REFERENCE, $sale->payment_reference);
        $this->assertSame(0, CustomerPayment::query()->count());
        $this->assertSame(0, PaymentAllocation::query()->count());
        $this->assertNull($invoice->payment_method);
        $this->assertNull($invoice->payment_reference);
    }

    public function test_unpaid_invoice_derives_unpaid_status_and_full_outstanding(): void
    {
        $invoice = $this->issuePendingInvoice('Bank Transfer', 'PENDING-2');
        $summary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice);

        $this->assertSame(StatutoryInvoicePaymentStatus::Unpaid, $summary->status);
        $this->assertSame(0.0, $summary->amountReceived);
        $this->assertSame((float) $invoice->invoice_value, $summary->amountOutstanding);
        $this->assertTrue($summary->posPaymentPending);
        $this->assertSame('Bank Transfer', $summary->posExpectedPaymentMethod);
        $this->assertFalse($summary->reconciliationRequired);
    }

    public function test_expected_payment_method_does_not_create_actual_payment(): void
    {
        $this->issuePendingInvoice('Bank Transfer', 'PENDING-3');

        $this->assertSame(0, CustomerPayment::query()->count());
        $this->assertSame(0, PaymentAllocation::query()->count());
    }

    public function test_unpaid_pos_invoice_pdf_shows_unpaid_without_paid_status(): void
    {
        $invoice = $this->issuePendingInvoice('Bank Transfer', 'PENDING-PDF-1');
        $documents = app(StatutoryDocumentService::class);
        $binary = $documents->binary($documents->generate($invoice));
        $text = $this->pdfText($binary);

        $this->assertStringContainsString('UNPAID', $text);
        $this->assertStringNotContainsString("\nPaid\n", $text);
    }

    public function test_finance_can_record_partial_and_final_payments_for_pending_pos_invoice(): void
    {
        $invoice = $this->setInvoiceValue(
            $this->issuePendingInvoice('Bank Transfer', 'PENDING-FIN-1'),
            100000.00,
        );
        $customer = $invoice->inventorySale?->customer;
        $this->assertNotNull($customer);

        $this->payments->assertNormalPaymentRecordingAllowed($invoice);

        $this->recordPayment($customer, $invoice, 40000.00, 'HDFC D', reference: 'UTR-40K-1');
        $summary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice->fresh());
        $this->assertSame(StatutoryInvoicePaymentStatus::PartiallyPaid, $summary->status);
        $this->assertSame(40000.00, $summary->amountReceived);
        $this->assertSame(60000.00, $summary->amountOutstanding);

        $this->recordPayment($customer, $invoice->fresh(), 60000.00, 'HDFC D', reference: 'UTR-60K-1');
        $summary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice->fresh());
        $this->assertSame(StatutoryInvoicePaymentStatus::Paid, $summary->status);
        $this->assertSame(100000.00, $summary->amountReceived);
        $this->assertSame(0.0, $summary->amountOutstanding);
        $this->assertFalse($summary->posPaymentPending);
        $this->assertSame(2, CustomerPayment::query()->count());
        $this->assertSame(2, PaymentAllocation::query()->count());
    }

    public function test_counter_store_accepts_payment_pending_submission(): void
    {
        $product = $this->stockSerializedProduct('PENDING-HTTP-1');

        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), [
                'branch_id' => $this->branch->id,
                'customer_name' => 'Pending Buyer',
                'customer_phone' => '9111222333',
                'payment_status' => PosSalePaymentState::PAYMENT_STATUS_PENDING,
                'payment_method' => 'Bank Transfer',
                'lines' => [[
                    'product_id' => $product->id,
                    'qty' => 1,
                    'serials' => 'SN-PENDING-HTTP-1',
                ]],
                'place_of_supply_state' => 'Delhi',
            ])
            ->assertRedirect();

        $sale = InventorySale::query()->latest('id')->first();
        $this->assertNotNull($sale);
        $this->assertTrue(PosSalePaymentState::isPaymentPending($sale));
    }

    public function test_paid_flow_rejects_reserved_payment_pending_reference(): void
    {
        $product = $this->stockSerializedProduct('RESERVED-REF-1');

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Paid Buyer', 'phone' => '9300000001'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['SN-RESERVED-REF-1'],
            ]],
            paymentMethod: 'Bank Transfer',
            actor: $this->seller,
            statutory: ['place_of_supply_state' => 'Delhi'],
            paymentReference: PosSalePaymentState::PAYMENT_PENDING_REFERENCE,
            paymentReceived: true,
        );
    }

    public function test_forward_pending_invoice_does_not_require_historical_backfill(): void
    {
        $invoice = $this->issuePendingInvoice('Bank Transfer', 'PENDING-NOBF-1');
        $reconciliation = app(StatutoryInvoicePaymentReconciliationService::class);

        $this->assertTrue($reconciliation->isForwardPaymentPendingPosInvoice($invoice));
        $this->assertFalse($reconciliation->requiresReconciliation($invoice));
        $this->assertFalse($reconciliation->allowsBackfill($invoice));
    }

    private function completePaidSale(string $method, string $marker): InventorySale
    {
        $product = $this->stockSerializedProduct($marker);

        return $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Paid Buyer', 'phone' => '910000'.substr(preg_replace('/\D/', '', $marker), 0, 4)],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['SN-'.$marker],
            ]],
            paymentMethod: $method,
            actor: $this->seller,
            statutory: ['place_of_supply_state' => 'Delhi'],
            paymentReceived: true,
        );
    }

    private function completePendingSale(?string $expectedMethod, string $marker): InventorySale
    {
        $product = $this->stockSerializedProduct($marker);

        return $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Pending Buyer', 'phone' => '920000'.substr(preg_replace('/\D/', '', $marker), 0, 4)],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['SN-'.$marker],
            ]],
            paymentMethod: (string) ($expectedMethod ?? ''),
            actor: $this->seller,
            statutory: ['place_of_supply_state' => 'Delhi'],
            paymentReceived: false,
        );
    }

    private function issuePendingInvoice(?string $expectedMethod, string $marker): StatutoryInvoice
    {
        $sale = $this->completePendingSale($expectedMethod, $marker);

        return $this->invoices->issueFromPosSale($sale, $this->seller);
    }

    private function stockSerializedProduct(string $marker): InventoryProduct
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'MFS110-'.$marker,
            'name' => 'Mantra MFS110 '.$marker,
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($product, $this->branch, ['SN-'.$marker], $this->seller);

        return $product;
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

    private function pdfText(string $pdf): string
    {
        return str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $pdf);
    }
}
