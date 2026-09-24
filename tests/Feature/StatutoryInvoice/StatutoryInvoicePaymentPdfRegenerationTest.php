<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\CustomerPaymentSource;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\PosHistoricalPaymentMethod;
use App\Enums\StatutoryInvoiceDocumentStatus;
use App\Enums\StatutoryInvoicePaymentStatus;
use App\Enums\StatutoryInvoiceStatus;
use App\Jobs\RegenerateStatutoryInvoicePdfAfterPaymentJob;
use App\Models\CustomerPayment;
use App\Models\EInvoiceRecord;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\PaymentAllocation;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceDocument;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\ServicePos\ServicePaymentService;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use App\Services\StatutoryInvoice\StatutoryInvoicePaymentBackfillService;
use App\Services\StatutoryInvoice\StatutoryInvoicePaymentPdfRegenerationService;
use App\Services\StatutoryInvoice\StatutoryInvoicePaymentReadService;
use App\Services\StatutoryInvoice\StatutoryInvoicePdfPresentationValidator;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class StatutoryInvoicePaymentPdfRegenerationTest extends TestCase
{
    use RefreshDatabase;

    private const IRN = 'a1b2c3d4e5f6789012345678901234567890123456789012345678901234abcd';

    private const JWT = 'eyJhbGciOiJFUzI1NiJ9.eyJpcnJuIjoiYTFiMmMzZDRlNWY2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0YWJjZCIsImdic3RpbiI6IjI3QUFJQ1AxMTI4MU1aNyIsImR0IjoiMDEvMDkvMjAyNiIsIm5vIjoiSU5WLTA3Njc2NSJ9.signature';

    private User $admin;

    private InventoryBranch $branch;

    private PosSaleService $sales;

    private StatutoryInvoiceService $invoices;

    private ServicePaymentService $payments;

    private StatutoryDocumentService $documents;

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
        $this->documents = app(StatutoryDocumentService::class);
    }

    public function test_unpaid_invoice_payment_allocation_updates_pdf_to_paid(): void
    {
        $invoice = $this->issuePosInvoice('Cash', 'PAY-PDF-PAID-1');
        $beforeChecksum = $this->documentChecksum($invoice);
        $textBefore = $this->invoicePdfText($invoice);

        $this->assertStringContainsString('UNPAID', $textBefore);

        $this->allocateFullPayment($invoice, 'Cash', 'CASH-PAID-1');

        $invoice = $invoice->fresh(['document']);
        $textAfter = $this->invoicePdfText($invoice);

        $this->assertSame(StatutoryInvoicePaymentStatus::Paid, app(StatutoryInvoicePaymentReadService::class)->summary($invoice)->status);
        $this->assertStringContainsString('Paid', $textAfter);
        $this->assertStringNotContainsString('UNPAID', $textAfter);
        $this->assertNotSame($beforeChecksum, $this->documentChecksum($invoice));
    }

    public function test_partial_allocation_updates_pdf_to_partially_paid(): void
    {
        $invoice = $this->issuePosInvoice('Cash', 'PAY-PDF-PART-1');
        $this->allocateAmount($invoice, 50.00, 'HDFC M', 'UTR-PART-1');

        $text = $this->invoicePdfText($invoice->fresh(['document']));

        $this->assertSame(
            StatutoryInvoicePaymentStatus::PartiallyPaid,
            app(StatutoryInvoicePaymentReadService::class)->summary($invoice->fresh())->status,
        );
        $this->assertStringContainsString('Partially Paid', $text);
        $this->assertPaymentStatusLabel($text, 'Partially Paid');
    }

    public function test_second_allocation_updates_pdf_from_partially_paid_to_paid(): void
    {
        $invoice = $this->issuePosInvoice('Cash', 'PAY-PDF-PART-2');
        $this->allocateAmount($invoice, 50.00, 'HDFC M', 'UTR-PART-2A');
        $partialText = $this->invoicePdfText($invoice->fresh(['document']));
        $this->assertStringContainsString('Partially Paid', $partialText);

        $this->allocateAmount($invoice, 68.00, 'HDFC D', 'UTR-PART-2B');

        $text = $this->invoicePdfText($invoice->fresh(['document']));
        $this->assertSame(
            StatutoryInvoicePaymentStatus::Paid,
            app(StatutoryInvoicePaymentReadService::class)->summary($invoice->fresh())->status,
        );
        $this->assertStringNotContainsString('Partially Paid', $text);
        $this->assertPaymentStatusLabel($text, 'Paid');
    }

    public function test_historical_backfill_payment_regenerates_pdf_from_canonical_state(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Bank Transfer', 'PAY-BF-1');
        $beforeChecksum = $this->documentChecksum($invoice);

        app(StatutoryInvoicePaymentBackfillService::class)->backfill(
            invoice: $invoice->fresh(['inventorySale.customer']),
            actor: $this->admin,
            input: [
                'outcome' => 'verified_payment',
                'amount' => 118.00,
                'payment_date' => '2026-09-10',
                'payment_method' => PosHistoricalPaymentMethod::HdfcM->value,
                'reference' => 'BF-UTR-001',
                'confirm' => '1',
                'verification_remark' => 'Verified historical payment.',
            ],
        );

        $invoice = $invoice->fresh(['document']);
        $text = $this->invoicePdfText($invoice);

        $this->assertSame(StatutoryInvoicePaymentStatus::Paid, app(StatutoryInvoicePaymentReadService::class)->summary($invoice)->status);
        $this->assertStringContainsString('Paid', $text);
        $this->assertStringContainsString('HDFC M', $text);
        $this->assertNotSame($beforeChecksum, $this->documentChecksum($invoice));
    }

    public function test_post_irn_payment_updates_pdf_payment_block_without_mutating_statutory_fields(): void
    {
        $invoice = $this->issuePosInvoice('UPI', 'PAY-IRN-1');
        $this->documents->generate($invoice->fresh(['items', 'eInvoiceRecord']));
        $this->attachSubmittedIrn($invoice);
        $this->documents->finalizeAfterIrn($invoice->fresh(['items', 'eInvoiceRecord']));

        $before = $invoice->fresh(['items', 'eInvoiceRecord', 'document']);
        $beforeIdentity = $before->only([
            'invoice_number',
            'taxable_value',
            'tax_total',
            'invoice_value',
            'cgst',
            'sgst',
            'igst',
        ]);
        $beforeIrn = $before->eInvoiceRecord?->only(['irn', 'ack_no', 'ack_date', 'signed_qr', 'status']);
        $beforeChecksum = $this->documentChecksum($before);

        $this->allocateFullPayment($before, 'HDFC M', 'IRN-PAY-UTR-1');

        $after = $before->fresh(['items', 'eInvoiceRecord', 'document']);
        $text = $this->invoicePdfText($after);

        foreach ($beforeIdentity as $key => $value) {
            $this->assertSame((string) $value, (string) $after->{$key}, $key);
        }
        foreach ($beforeIrn as $key => $value) {
            if ($key === 'ack_date') {
                $this->assertSame(
                    $before->eInvoiceRecord?->ack_date?->toDateTimeString(),
                    $after->eInvoiceRecord?->ack_date?->toDateTimeString(),
                    $key,
                );

                continue;
            }

            $this->assertSame($value, $after->eInvoiceRecord?->{$key}, $key);
        }
        $this->assertStringContainsString(self::IRN, $text);
        $this->assertStringContainsString('Ack No: ACK-2341', $text);
        $this->assertStringContainsString('% signed-qr-image', $this->documents->binary($after->document));
        $this->assertStringContainsString('HDFC M', $text);
        $this->assertPaymentStatusLabel($text, 'Paid');
        $this->assertNotSame($beforeChecksum, $this->documentChecksum($after));
    }

    public function test_idempotent_allocation_replay_does_not_dispatch_duplicate_regeneration_job(): void
    {
        Queue::fake();

        $invoice = $this->issuePosInvoice('Cash', 'PAY-DUP-1');
        $customer = $invoice->inventorySale?->customer;
        $this->assertNotNull($customer);

        $payment = $this->payments->recordPayment(
            customer: $customer,
            amount: 118.00,
            method: 'Cash',
            paymentDate: now(),
            actor: $this->admin,
            reference: 'DUP-REF-1',
        );

        $this->payments->allocatePayment($payment, $invoice, 118.00, $this->admin, 'dup-alloc-key');
        $this->payments->allocatePayment($payment, $invoice, 118.00, $this->admin, 'dup-alloc-key');

        Queue::assertPushed(RegenerateStatutoryInvoicePdfAfterPaymentJob::class, 1);
        $this->assertSame(1, PaymentAllocation::query()->where('statutory_invoice_id', $invoice->id)->count());
        $this->assertSame(1, CustomerPayment::query()->where('reference', 'DUP-REF-1')->count());
    }

    public function test_pdf_regeneration_failure_does_not_roll_back_payment(): void
    {
        $invoice = $this->issuePosInvoice('Cash', 'PAY-FAIL-1');
        $customer = $invoice->inventorySale?->customer;
        $this->assertNotNull($customer);

        $mock = Mockery::mock(StatutoryInvoicePaymentPdfRegenerationService::class);
        $mock->shouldReceive('regenerateAfterPayment')
            ->once()
            ->andThrow(new RuntimeException('PDF renderer unavailable.'));
        $this->app->instance(StatutoryInvoicePaymentPdfRegenerationService::class, $mock);

        $payment = $this->payments->recordPayment(
            customer: $customer,
            amount: 118.00,
            method: 'Cash',
            paymentDate: now(),
            actor: $this->admin,
            reference: 'FAIL-REF-1',
        );

        $allocation = $this->payments->allocatePayment($payment, $invoice, 118.00, $this->admin);

        $this->assertSame(118.00, (float) $allocation->amount);
        $this->assertSame(
            StatutoryInvoicePaymentStatus::Paid,
            app(StatutoryInvoicePaymentReadService::class)->summary($invoice->fresh())->status,
        );
    }

    public function test_job_skips_cancelled_invoice_without_regenerating_pdf(): void
    {
        $invoice = $this->issuePosInvoice('Cash', 'PAY-CANCEL-1');
        $beforeChecksum = $this->documentChecksum($invoice);

        StatutoryInvoice::query()->whereKey($invoice->id)->update([
            'status' => StatutoryInvoiceStatus::Cancelled,
        ]);

        $job = new RegenerateStatutoryInvoicePdfAfterPaymentJob($invoice->id);
        $job->handle(app(StatutoryInvoicePaymentPdfRegenerationService::class));

        $this->assertSame($beforeChecksum, $this->documentChecksum($invoice->fresh(['document'])));
    }

    public function test_cancelled_invoice_rejects_allocation_and_does_not_dispatch_regeneration_job(): void
    {
        Queue::fake();

        $invoice = $this->issuePosInvoice('Cash', 'PAY-CANCEL-2');
        $customer = $invoice->inventorySale?->customer;
        $this->assertNotNull($customer);

        StatutoryInvoice::query()->whereKey($invoice->id)->update([
            'status' => StatutoryInvoiceStatus::Cancelled,
        ]);

        $payment = $this->payments->recordPayment(
            customer: $customer,
            amount: 118.00,
            method: 'Cash',
            paymentDate: now(),
            actor: $this->admin,
            reference: 'CANCEL-REF-1',
        );

        try {
            $this->payments->allocatePayment($payment, $invoice->fresh(), 118.00, $this->admin);
            $this->fail('Expected allocation on cancelled invoice to fail.');
        } catch (ValidationException) {
            // expected
        }

        Queue::assertNotPushed(RegenerateStatutoryInvoicePdfAfterPaymentJob::class);
    }

    public function test_invoice_without_payment_keeps_existing_pdf_checksum(): void
    {
        $invoice = $this->issuePosInvoice('Cash', 'PAY-NOOP-1');
        $checksum = $this->documentChecksum($invoice);
        $text = $this->invoicePdfText($invoice);

        $this->assertStringContainsString('UNPAID', $text);
        $this->assertSame($checksum, $this->documentChecksum($invoice->fresh(['document'])));
    }

    public function test_failed_regeneration_job_marks_document_failed_and_is_retryable(): void
    {
        $invoice = $this->issuePosInvoice('Cash', 'PAY-JOB-FAIL-1');
        $this->documents->generate($invoice->fresh(['items', 'eInvoiceRecord']));

        $validator = Mockery::mock(StatutoryInvoicePdfPresentationValidator::class);
        $validator->shouldReceive('validate')
            ->once()
            ->andThrow(new RuntimeException('Presentation validator rejected PDF.'));
        $this->app->instance(StatutoryInvoicePdfPresentationValidator::class, $validator);

        try {
            app(StatutoryInvoicePaymentPdfRegenerationService::class)
                ->regenerateAfterPayment($invoice->fresh(['items', 'eInvoiceRecord', 'document']));
            $this->fail('Expected regeneration failure.');
        } catch (RuntimeException) {
            // expected
        }

        $document = StatutoryInvoiceDocument::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(StatutoryInvoiceDocumentStatus::Failed, $document->status);
        $this->assertStringContainsString('Presentation validator rejected PDF.', (string) $document->last_error);
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
            customer: ['name' => 'Payment PDF Customer', 'phone' => '920000'.substr(preg_replace('/\D/', '', $marker), 0, 4)],
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

        $invoice = $this->invoices->issueFromPosSale($sale, $this->admin);
        $this->documents->generate($invoice->fresh(['items', 'eInvoiceRecord']));

        return $invoice->fresh(['document', 'inventorySale.customer', 'items', 'eInvoiceRecord']);
    }

    private function issueHistoricalPosInvoice(string $paymentMethod, string $marker): StatutoryInvoice
    {
        $invoice = $this->issuePosInvoice($paymentMethod, $marker);
        $issuedAt = now()->subDays(10);
        StatutoryInvoice::query()->whereKey($invoice->id)->update(['issued_at' => $issuedAt]);

        return $invoice->fresh(['document', 'inventorySale.customer', 'items', 'eInvoiceRecord']);
    }

    private function allocateFullPayment(StatutoryInvoice $invoice, string $method, ?string $reference = null): void
    {
        $this->allocateAmount($invoice, 118.00, $method, $reference);
    }

    private function allocateAmount(
        StatutoryInvoice $invoice,
        float $amount,
        string $method,
        ?string $reference = null,
    ): void {
        $customer = $invoice->inventorySale?->customer;
        $this->assertNotNull($customer);

        $payment = $this->payments->recordPayment(
            customer: $customer,
            amount: $amount,
            method: $method,
            paymentDate: now(),
            actor: $this->admin,
            reference: $reference,
            source: CustomerPaymentSource::FinanceReceipt,
        );

        $this->payments->allocatePayment(
            payment: $payment,
            invoice: $invoice,
            amount: $amount,
            actor: $this->admin,
        );
    }

    private function attachSubmittedIrn(StatutoryInvoice $invoice): void
    {
        EInvoiceRecord::query()->updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'provider' => 'test',
                'irn' => self::IRN,
                'ack_no' => 'ACK-2341',
                'ack_date' => '2026-09-07 18:40:00',
                'signed_qr' => self::JWT,
                'status' => EInvoiceRecordStatus::Submitted->value,
                'response_payload' => ['ok' => true],
            ],
        );
    }

    private function invoicePdfText(StatutoryInvoice $invoice): string
    {
        $document = $invoice->document ?? StatutoryInvoiceDocument::query()->where('invoice_id', $invoice->id)->firstOrFail();

        return $this->pdfText($this->documents->binary($document));
    }

    private function documentChecksum(StatutoryInvoice $invoice): ?string
    {
        return $invoice->document?->checksum
            ?? StatutoryInvoiceDocument::query()->where('invoice_id', $invoice->id)->value('checksum');
    }

    private function assertPaymentStatusLabel(string $text, string $expectedLabel): void
    {
        $this->assertStringContainsString('Payment Status', $text);
        $this->assertStringContainsString($expectedLabel, $text);
    }

    private function pdfText(string $pdf): string
    {
        return str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $pdf);
    }
}
