<?php

namespace Tests\Feature\Finance;

use App\Enums\StatutoryInvoicePaymentStatus;
use App\Models\AuditLog;
use App\Models\CustomerPayment;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\InventoryProduct;
use App\Models\PaymentAllocation;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\ServicePos\ServicePaymentService;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use App\Services\StatutoryInvoice\StatutoryInvoiceCancellationOrchestrator;
use App\Services\StatutoryInvoice\StatutoryInvoicePaymentReadService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StatutoryInvoicePosPaymentRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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
        $this->payments = app(ServicePaymentService::class);
    }

    public function test_unpaid_pos_invoice_shows_unpaid_payment_status_on_pdf(): void
    {
        $invoice = $this->issuePosInvoice('Cash', 'CASH-TENDER-1');
        $documents = app(StatutoryDocumentService::class);
        $binary = $documents->binary($documents->generate($invoice));
        $text = $this->pdfText($binary);

        $summary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice);
        $this->assertSame(StatutoryInvoicePaymentStatus::Unpaid, $summary->status);
        $this->assertStringContainsString('UNPAID', $text);
        $this->assertStringContainsString('Cash', $text);
    }

    public function test_cash_payment_can_be_recorded_and_marks_invoice_paid(): void
    {
        $invoice = $this->issuePosInvoice('Cash', 'CASH-TENDER-2');
        $customer = $invoice->inventorySale?->customer;
        $this->assertNotNull($customer);

        $this->recordPayment($customer, $invoice, 118.00, 'Cash', reference: 'CASH-RCPT-1');

        $summary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice->fresh());
        $this->assertSame(StatutoryInvoicePaymentStatus::Paid, $summary->status);
        $this->assertSame(118.00, $summary->amountReceived);
    }

    public function test_upi_payment_can_be_recorded_with_reference(): void
    {
        $invoice = $this->issuePosInvoice('UPI', 'UPI-TENDER-1');
        $customer = $invoice->inventorySale?->customer;
        $this->assertNotNull($customer);

        $this->recordPayment($customer, $invoice, 118.00, 'UPI', reference: 'UPIUTR123456');

        $summary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice->fresh());
        $this->assertSame('UPI', $summary->latestPaymentMethod);
        $this->assertSame('UPIUTR123456', $summary->latestReference);
    }

    public function test_bank_transfer_requires_bank_branch_and_reference(): void
    {
        $invoice = $this->issuePosInvoice('Bank Transfer', 'BANK-TENDER-1');
        $customer = $invoice->inventorySale?->customer;
        $this->assertNotNull($customer);

        $this->expectException(ValidationException::class);
        $this->payments->recordPayment(
            customer: $customer,
            amount: 118.00,
            method: 'Bank Transfer',
            paymentDate: now(),
            actor: $this->admin,
        );
    }

    public function test_bank_transfer_payment_records_bank_branch_and_reference(): void
    {
        $invoice = $this->issuePosInvoice('Bank Transfer', 'BANK-TENDER-2');
        $customer = $invoice->inventorySale?->customer;
        $this->assertNotNull($customer);

        $this->recordPayment(
            customer: $customer,
            invoice: $invoice,
            amount: 118.00,
            method: 'Bank Transfer',
            reference: 'NEFT6746REF001',
            bankName: 'HDFC Bank',
            bankBranch: 'Nehru Place',
        );

        $summary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice->fresh());
        $this->assertSame('HDFC Bank', $summary->latestBankName);
        $this->assertSame('Nehru Place', $summary->latestBankBranch);
        $this->assertSame('NEFT6746REF001', $summary->latestReference);
    }

    public function test_partial_payment_leaves_outstanding_balance(): void
    {
        $invoice = $this->issuePosInvoice('Cash', 'PARTIAL-1');
        $customer = $invoice->inventorySale?->customer;
        $this->assertNotNull($customer);

        $this->recordPayment($customer, $invoice, 50.00, 'Cash');

        $summary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice->fresh());
        $this->assertSame(StatutoryInvoicePaymentStatus::PartiallyPaid, $summary->status);
        $this->assertSame(68.00, $summary->amountOutstanding);
    }

    public function test_duplicate_reference_for_same_customer_is_rejected(): void
    {
        $invoice = $this->issuePosInvoice('UPI', 'DUP-REF-1');
        $customer = $invoice->inventorySale?->customer;
        $this->assertNotNull($customer);

        $this->recordPayment($customer, $invoice, 118.00, 'UPI', reference: 'DUPLICATE-UTR');

        $otherInvoice = $this->issuePosInvoice('UPI', 'DUP-REF-2');
        $this->expectException(ValidationException::class);
        $this->payments->recordPayment(
            customer: $customer,
            amount: 50.00,
            method: 'UPI',
            paymentDate: now(),
            actor: $this->admin,
            reference: 'DUPLICATE-UTR',
        );
    }

    public function test_payment_idempotency_key_prevents_duplicate_records(): void
    {
        $invoice = $this->issuePosInvoice('Cash', 'IDEM-1');
        $customer = $invoice->inventorySale?->customer;
        $this->assertNotNull($customer);

        $key = 'pos-payment-idem-1';
        $first = $this->payments->recordPayment(
            customer: $customer,
            amount: 118.00,
            method: 'Cash',
            paymentDate: now(),
            actor: $this->admin,
            idempotencyKey: $key,
        );
        $second = $this->payments->recordPayment(
            customer: $customer,
            amount: 118.00,
            method: 'Cash',
            paymentDate: now(),
            actor: $this->admin,
            idempotencyKey: $key,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CustomerPayment::query()->count());
    }

    public function test_payment_recording_writes_audit_events(): void
    {
        $invoice = $this->issuePosInvoice('Cash', 'AUDIT-1');
        $customer = $invoice->inventorySale?->customer;
        $this->assertNotNull($customer);

        $this->recordPayment($customer, $invoice, 118.00, 'Cash', reference: 'AUDIT-CASH-1');

        $this->assertSame(1, AuditLog::query()->where('event', ServicePaymentService::EVENT_PAYMENT_RECORDED)->count());
        $this->assertSame(1, AuditLog::query()->where('event', ServicePaymentService::EVENT_PAYMENT_ALLOCATED)->count());
    }

    public function test_payment_matches_invoice_and_sale(): void
    {
        $invoice = $this->issuePosInvoice('Bank Transfer', 'MATCH-1');
        $sale = $invoice->inventorySale;
        $customer = $sale?->customer;
        $this->assertNotNull($sale);
        $this->assertNotNull($customer);

        $this->recordPayment(
            customer: $customer,
            invoice: $invoice,
            amount: 118.00,
            method: 'Bank Transfer',
            reference: 'MATCH-UTR-001',
            bankName: 'ICICI Bank',
            bankBranch: 'Delhi Main',
        );

        $allocation = PaymentAllocation::query()->where('statutory_invoice_id', $invoice->id)->first();
        $this->assertNotNull($allocation);
        $this->assertSame($invoice->id, $allocation->statutory_invoice_id);
        $this->assertSame($sale->id, $invoice->inventory_sale_id);
    }

    public function test_cancelled_invoice_sees_payment_state_without_auto_refund(): void
    {
        Http::fake();
        $invoice = $this->issuePosInvoice('Cash', 'CANCEL-PAY-1');
        $customer = $invoice->inventorySale?->customer;
        $this->assertNotNull($customer);

        $this->recordPayment($customer, $invoice, 118.00, 'Cash', reference: 'CASH-BEFORE-CANCEL');

        app(StatutoryInvoiceCancellationOrchestrator::class)->cancel(
            invoice: $invoice,
            actor: $this->admin,
            reason: 'Payment state regression',
            idempotencyKey: StatutoryInvoiceCancellationOrchestrator::DEFAULT_IDEMPOTENCY_PREFIX.$invoice->id,
        );

        $summary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice->fresh());
        $this->assertSame(StatutoryInvoicePaymentStatus::Paid, $summary->status);
        $this->assertSame(118.00, $summary->amountReceived);

        Http::assertNothingSent();
    }

    public function test_finance_invoice_show_exposes_payment_summary(): void
    {
        $invoice = $this->issuePosInvoice('Cash', 'UI-PAY-1');

        $this->actingAs($this->admin)
            ->get(route('finance.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Payment record', false)
            ->assertSee('Unpaid', false)
            ->assertSee('POS tender at checkout', false);
    }

    private function issuePosInvoice(string $paymentMethod, string $marker): StatutoryInvoice
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
        app(InventoryStockService::class)->stockInSerialized($product, $this->branch, ['SN-'.$marker], $this->admin);

        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Payment Test Customer', 'phone' => '900000'.substr(preg_replace('/\D/', '', $marker), 0, 4)],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['SN-'.$marker],
            ]],
            paymentMethod: $paymentMethod,
            actor: $this->admin,
            statutory: [
                'place_of_supply_state' => 'Delhi',
            ],
        );

        return $this->invoices->issueFromPosSale($sale, $this->admin);
    }

    private function recordPayment(
        InventoryCustomer $customer,
        StatutoryInvoice $invoice,
        float $amount,
        string $method,
        ?string $reference = null,
        ?string $bankName = null,
        ?string $bankBranch = null,
    ): void {
        $payment = $this->payments->recordPayment(
            customer: $customer,
            amount: $amount,
            method: $method,
            paymentDate: now(),
            actor: $this->admin,
            reference: $reference,
            bankName: $bankName,
            bankBranch: $bankBranch,
        );

        $this->payments->allocatePayment(
            payment: $payment,
            invoice: $invoice,
            amount: $amount,
            actor: $this->admin,
        );
    }

    private function pdfText(string $pdf): string
    {
        return str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $pdf);
    }
}
