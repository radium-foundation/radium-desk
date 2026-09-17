<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentStatus;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\EInvoiceRecord;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceDocument;
use App\Models\User;
use App\Services\StatutoryInvoice\Data\StatutoryInvoicePdfPayload;
use App\Services\StatutoryInvoice\SimplePdfRenderer;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use App\Services\StatutoryInvoice\StatutoryInvoicePdfAssetException;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StatutoryInvoicePdfPresentationTest extends TestCase
{
    use RefreshDatabase;

    private StatutoryInvoiceService $invoices;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-07 18:28:19');
        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->configureLocationSellerIdentity();
        config([
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'channel_ingest.auto_issue_invoice' => false,
        ]);

        $this->invoices = app(StatutoryInvoiceService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_b2c_pdf_is_professional_and_omits_irn_debug(): void
    {
        $invoice = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder(
                'RD-PDF-B2C',
                buyerGstin: null,
                description: 'Information technology (IT) consulting & support services (SAC - 998313) - (Sr. No. 10500255) - 1 Year Unlimited',
                companion: 'RD Technical Support — included',
            ),
            $this->actor,
        );
        $pdf = $this->text($this->pdf($invoice->id));

        $this->assertStringContainsString('TAX INVOICE', $pdf);
        $this->assertStringContainsString('Phil Technologies', $pdf);
        $this->assertStringContainsString($this->configuredSellerGstin('mumbai'), $pdf);
        $this->assertStringContainsString('Invoice No.', $pdf);
        $this->assertStringContainsString($invoice->invoice_number, $pdf);
        $this->assertStringContainsString('BILL TO', $pdf);
        $this->assertStringContainsString('CHANDRAKANT GANPAT SARODE', $pdf);
        $this->assertStringContainsString('GSTIN Unregistered', $pdf);
        $this->assertStringContainsString('Place of Supply Maharashtra', $pdf);
        $this->assertStringContainsString('CIN: U72300DL2015PTC280283', $pdf);
        $this->assertStringContainsString('Tax Rate', $pdf);
        $this->assertStringContainsString('consulting', $pdf);
        $this->assertStringContainsString('HSN/SAC', $pdf);
        $this->assertStringContainsString('998313', $pdf);
        $this->assertStringNotContainsString('998314', $pdf);
        $this->assertStringContainsString('Qty', $pdf);
        $this->assertStringContainsString('UQC', $pdf);
        $this->assertStringContainsString('Rs.422.88', $pdf);
        $this->assertStringContainsString('18.00%', $pdf);
        $this->assertStringContainsString('CGST', $pdf);
        $this->assertStringContainsString('SGST', $pdf);
        $this->assertStringContainsString('Rs.38.06', $pdf);
        $this->assertStringNotContainsString('IGST Rs.0.00', $pdf);
        $this->assertStringContainsString('Rs.499.00', $pdf);
        $this->assertStringContainsString('TOTAL INVOICE VALUE', $pdf);
        $this->assertStringContainsString('Four Hundred Ninety-Nine Rupees Only', $pdf);
        $this->assertStringContainsString('Thank you for your business.', $pdf);
        $this->assertStringNotContainsString('Thanks for shopping', $pdf);
        $this->assertStringContainsString('RD Technical Support - included', $pdf);
        $this->assertStringNotContainsString('???', $pdf);
        $this->assertStringNotContainsString('unset', $pdf);
        $this->assertStringNotContainsString('GSTIN B2C', $pdf);
        $this->assertStringNotContainsString('IRN not submitted', $pdf);
        $this->assertStringNotContainsString('b2c_not_eligible', $pdf);
        $this->assertStringNotContainsString('worker_may_mint', $pdf);
        $this->assertDoesNotMatchRegularExpression('/IRN [A-Za-z0-9]{8,}/', $pdf);
    }

    public function test_b2b_queued_pdf_does_not_invent_or_debug_irn(): void
    {
        $invoice = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder(
                'RB-PDF-B2B',
                buyerGstin: '07AAAAA0000A1Z5',
                description: 'Mantra MFS 110 L1 Single Fingerprint Biometric Scanner',
                channel: StatutoryInvoiceChannel::RadiumBoxCom,
                hsnSac: '84716050',
            ),
            $this->actor,
        );
        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $pdf = $this->text($this->pdf($invoice->id));

        $this->assertSame(EInvoiceRecordStatus::Queued->value, $record?->status);
        $this->assertNull($record?->irn);
        $this->assertStringContainsString('TAX INVOICE', $pdf);
        $this->assertStringContainsString('07AAAAA0000A1Z5', $pdf);
        $this->assertStringContainsString('84716050', $pdf);
        $this->assertStringNotContainsString('IRN not submitted', $pdf);
        $this->assertStringNotContainsString('queued', $pdf);
        $this->assertStringNotContainsString('b2b_eligible', $pdf);
        $this->assertDoesNotMatchRegularExpression('/IRN [A-Za-z0-9]{8,}/', $pdf);
    }

    public function test_phase_a_b2b_service_pdf_is_skipped_and_does_not_invent_irn(): void
    {
        $invoice = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD-PDF-B2B', buyerGstin: '07AAAAA0000A1Z5'),
            $this->actor,
        );
        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $pdf = $this->text($this->pdf($invoice->id));

        $this->assertSame(EInvoiceRecordStatus::Skipped->value, $record?->status);
        $this->assertSame('issuance_policy_service_excluded', $record?->response_payload['skip_reason'] ?? null);
        $this->assertNull($record?->irn);
        $this->assertStringContainsString('TAX INVOICE', $pdf);
        $this->assertStringContainsString('07AAAAA0000A1Z5', $pdf);
        $this->assertStringNotContainsString('IRN not submitted', $pdf);
        $this->assertStringNotContainsString('issuance_policy_service_excluded', $pdf);
        $this->assertDoesNotMatchRegularExpression('/IRN [A-Za-z0-9]{8,}/', $pdf);
    }

    public function test_submitted_irn_is_printed_with_acknowledgement(): void
    {
        $invoice = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD-PDF-IRN', buyerGstin: '07AAAAA0000A1Z5'),
            $this->actor,
        );
        EInvoiceRecord::query()->updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'provider' => 'test',
                'irn' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
                'ack_no' => 'ACK-1001',
                'ack_date' => '2026-09-07 18:40:00',
                'signed_qr' => $this->jwtSignedQr(),
                'status' => EInvoiceRecordStatus::Submitted->value,
                'response_payload' => ['ok' => true],
            ],
        );
        $this->regenerate($invoice->id);
        $binary = $this->pdf($invoice->id);
        $pdf = $this->text($binary);

        $this->assertStringContainsString('IRN', $pdf);
        $this->assertStringContainsString('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2', $pdf);
        $this->assertStringContainsString('Ack No: ACK-1001', $pdf);
        $this->assertStringContainsString('% signed-qr-image', $binary);
        $this->assertStringNotContainsString($this->jwtSignedQr(), $binary);
        $this->assertStringNotContainsString('IRN not submitted', $pdf);
    }

    public function test_failed_irn_does_not_print_provider_debug_or_invent_irn(): void
    {
        $invoice = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD-PDF-FAIL', buyerGstin: '07AAAAA0000A1Z5'),
            $this->actor,
        );
        EInvoiceRecord::query()->updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'provider' => 'test',
                'irn' => null,
                'status' => EInvoiceRecordStatus::Failed->value,
                'response_payload' => [
                    'error' => 'GSP timeout stack TRACE-SECRET-99',
                    'skip_reason' => 'provider_http_disabled',
                ],
            ],
        );
        $this->regenerate($invoice->id);
        $pdf = $this->text($this->pdf($invoice->id));

        $this->assertStringNotContainsString('IRN not submitted', $pdf);
        $this->assertStringNotContainsString('TRACE-SECRET-99', $pdf);
        $this->assertStringNotContainsString('GSP timeout', $pdf);
        $this->assertStringNotContainsString('provider_http_disabled', $pdf);
        $this->assertDoesNotMatchRegularExpression('/IRN [A-Za-z0-9]{8,}/', $pdf);
    }

    public function test_long_address_and_description_wrap_without_debug_placeholders(): void
    {
        $renderer = new SimplePdfRenderer;
        $pdf = $this->text($renderer->render(new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-67200',
            issuedAt: '2026-09-07 19:08:00',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: '1312, Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            buyerName: 'Principal Arya Kanya Inter College Banda with an unusually long institutional title',
            buyerGstin: null,
            billingAddress: 'Ward No. 12, Civil Lines, Near Old Bus Stand, Banda, Uttar Pradesh 210001, India',
            placeOfSupply: 'Uttar Pradesh',
            lines: [[
                'description' => 'Information technology (IT) consulting & support services (SAC - 998313) - (Sr. No. 10500255) - 1 Year Unlimited plus an extra clause for wrapping',
                'hsnSac' => '998314',
                'qty' => 1,
                'unitPrice' => '422.88',
                'taxableValue' => '422.88',
                'gstPercentage' => '18.00%',
                'cgst' => '0.00',
                'sgst' => '0.00',
                'igst' => '76.12',
                'taxTotal' => '76.12',
                'lineTotal' => '499.00',
            ]],
            taxableValue: '422.88',
            gstRate: '18.00%',
            taxTotal: '76.12',
            cgst: '0.00',
            sgst: '0.00',
            igst: '76.12',
            invoiceValue: '499.00',
        )));

        $this->assertStringContainsString('Principal Arya Kanya Inter College', $pdf);
        $this->assertStringContainsString('Civil Lines', $pdf);
        $this->assertStringContainsString('Place of Supply Uttar Pradesh', $pdf);
        $this->assertStringContainsString('BILL TO', $pdf);
        $this->assertStringContainsString('consulting', $pdf);
        $this->assertStringContainsString('998314', $pdf);
        $this->assertStringNotContainsString('???', $pdf);
        $this->assertStringNotContainsString('unset', $pdf);
        $this->assertStringNotContainsString('IRN not submitted', $pdf);
    }

    public function test_renderer_never_prints_irn_from_unsubmitted_payload(): void
    {
        $renderer = new SimplePdfRenderer;
        $pdf = $this->text($renderer->render($this->payload()));

        $this->assertStringContainsString('TAX INVOICE', $pdf);
        $this->assertStringContainsString('HSN/SAC', $pdf);
        $this->assertStringContainsString('998314', $pdf);
        $this->assertStringNotContainsString('IRN not submitted', $pdf);
        $this->assertDoesNotMatchRegularExpression('/IRN [A-Za-z0-9]{8,}/', $pdf);
    }

    public function test_renderer_prints_only_supplied_irn(): void
    {
        $renderer = new SimplePdfRenderer;
        $pdf = $this->text($renderer->render($this->payload(
            irn: 'issued-irn-token-0001',
            ackNo: '112233',
            ackDate: '07 Sep 2026 18:40',
        )));

        $this->assertStringContainsString('IRN', $pdf);
        $this->assertStringContainsString('issued-irn-token-0001', $pdf);
        $this->assertStringContainsString('Ack No: 112233', $pdf);
        $this->assertStringContainsString('Date: 07 Sep 2026 18:40', $pdf);
        $this->assertStringContainsString('e-Invoice Verification', $pdf);
        $this->assertStringNotContainsString('IRN not submitted', $pdf);
    }

    public function test_multipage_invoice_repeats_header_and_keeps_totals_on_last_page(): void
    {
        $lines = [];
        for ($i = 1; $i <= 12; $i++) {
            $lines[] = [
                'description' => 'Information technology (IT) consulting & support services line '.$i.' (SAC - 998313) with additional wrapping text for multi-page invoices',
                'hsnSac' => '998314',
                'qty' => 1,
                'unitPrice' => '100.00',
                'taxableValue' => '100.00',
                'gstPercentage' => '18.00%',
                'cgst' => '0.00',
                'sgst' => '0.00',
                'igst' => '18.00',
                'taxTotal' => '18.00',
                'lineTotal' => '118.00',
            ];
        }

        $pdf = $this->text((new SimplePdfRenderer)->render(new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-27690',
            issuedAt: '2026-09-07 20:00:00',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '27AAICP1128M1Z7',
            sellerAddress: 'G40, Harmony Mall, Link Road, Goregaon, Mumbai 400104',
            sellerState: 'Maharashtra',
            buyerName: 'Long Name Customer',
            buyerGstin: null,
            billingAddress: 'Ward No. 12, Civil Lines, Banda, Uttar Pradesh 210001',
            placeOfSupply: 'Uttar Pradesh',
            lines: $lines,
            taxableValue: '1200.00',
            gstRate: '18.00%',
            taxTotal: '216.00',
            cgst: '0.00',
            sgst: '0.00',
            igst: '216.00',
            invoiceValue: '1416.00',
            serialNumbers: ['SN-1', 'SN-2', 'SN-3', 'SN-4', 'SN-5', 'SN-6'],
            sourceId: 'RDE900305',
        )));

        $this->assertMatchesRegularExpression('/Page 1 of [2-9]/', $pdf);
        $this->assertStringContainsString('TAX INVOICE', $pdf);
        $this->assertStringContainsString('TOTAL INVOICE VALUE', $pdf);
        $this->assertStringContainsString('Rs.1416.00', $pdf);
        $this->assertStringContainsString('Serial Numbers', $pdf);
        $this->assertStringContainsString('SN-1', $pdf);
        $this->assertStringContainsString('SN-6', $pdf);
        $this->assertStringContainsString('Order ID', $pdf);
        $this->assertStringContainsString('RDE900305', $pdf);
        $this->assertStringNotContainsString('ANNEXURE A', $pdf);
        $this->assertStringNotContainsString('IRN not submitted', $pdf);
        $this->assertStringNotContainsString('statutory:', $pdf);
    }

    public function test_ten_serials_show_first_page_summary_and_complete_annexure(): void
    {
        $serials = [];
        for ($i = 1; $i <= 10; $i++) {
            $serials[] = sprintf('SN-%02d', $i);
        }

        $binary = (new SimplePdfRenderer)->render(new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-076724',
            issuedAt: '2026-09-07 20:00:00',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: 'Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            buyerName: 'Hardware Buyer',
            buyerGstin: '07AAAAA0000A1Z5',
            billingAddress: '1 Test Street, Delhi',
            placeOfSupply: 'Delhi',
            lines: [[
                'description' => 'Mantra MFS 110 L1',
                'hsnSac' => '84716050',
                'qty' => 10,
                'unitPrice' => '100.00',
                'taxableValue' => '1000.00',
                'gstPercentage' => '18.00%',
                'cgst' => '90.00',
                'sgst' => '90.00',
                'igst' => '0.00',
                'taxTotal' => '180.00',
                'lineTotal' => '1180.00',
                'uqc' => 'PCS',
            ]],
            taxableValue: '1000.00',
            gstRate: '18.00%',
            taxTotal: '180.00',
            cgst: '90.00',
            sgst: '90.00',
            igst: '0.00',
            invoiceValue: '1180.00',
            serialNumbers: $serials,
            sourceId: 'RDE318400',
            orderId: 'RDE318400',
        ));
        $pdf = $this->text($binary);

        $this->assertStringContainsString('TAX INVOICE', $pdf);
        $this->assertStringContainsString('Serial Numbers', $pdf);
        $this->assertStringNotContainsString('* More serial numbers in Annexure A', $pdf);
        $this->assertStringNotContainsString('ANNEXURE A', $pdf);
        $this->assertStringContainsString('UQC', $pdf);
        $this->assertStringContainsString('PCS', $pdf);
        $this->assertStringContainsString('Rs.1180.00', $pdf);
        $seen = [];
        foreach ($serials as $serial) {
            $this->assertStringContainsString($serial, $pdf);
            $this->assertArrayNotHasKey($serial, $seen);
            $seen[$serial] = substr_count($pdf, $serial);
        }
        $this->assertSame(1, $seen['SN-09']);
        $this->assertSame(1, $seen['SN-10']);
        $this->assertGreaterThanOrEqual(1, $seen['SN-01']);
    }

    public function test_shipping_and_payment_print_and_signed_qr_is_omitted_without_irn(): void
    {
        $pdf = $this->text((new SimplePdfRenderer)->render(new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-276799',
            issuedAt: '2026-09-07 18:28:19',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '27AAICP1128M1Z7',
            sellerAddress: 'G40, Harmony Mall, Link Road, Goregaon, Mumbai 400104',
            sellerState: 'Maharashtra',
            buyerName: 'CHANDRAKANT GANPAT SARODE',
            buyerGstin: null,
            billingAddress: '312 dhamankar plaza, Bhiwandi',
            placeOfSupply: 'Maharashtra',
            lines: [[
                'description' => 'Information technology (IT) consulting & support services (SAC - 998313)',
                'hsnSac' => '998313',
                'qty' => 1,
                'unitPrice' => '422.88',
                'taxableValue' => '422.88',
                'gstPercentage' => '18.00%',
                'cgst' => '38.06',
                'sgst' => '38.06',
                'igst' => '0.00',
                'taxTotal' => '76.12',
                'lineTotal' => '499.00',
            ]],
            taxableValue: '422.88',
            gstRate: '18.00%',
            taxTotal: '76.12',
            cgst: '38.06',
            sgst: '38.06',
            igst: '0.00',
            invoiceValue: '499.00',
            shippingAddress: 'Warehouse Gate 2, Andheri East, Mumbai 400069',
            paymentMethod: 'UPI',
            signedQr: 'eyJhbGciOiJFUzI1NiJ9.fake-signed-qr',
        )));

        $this->assertStringContainsString('SHIP TO', $pdf);
        $this->assertStringContainsString('Andheri East', $pdf);
        $this->assertStringContainsString('Payment', $pdf);
        $this->assertStringContainsString('UPI', $pdf);
        $this->assertStringContainsString('Payment Status', $pdf);
        $this->assertStringContainsString('Paid', $pdf);
        $this->assertStringNotContainsString('Payment Date', $pdf);
        $this->assertStringNotContainsString('e-Invoice Verification', $pdf);
        $this->assertDoesNotMatchRegularExpression('/IRN [A-Za-z0-9]{8,}/', $pdf);
        $this->assertStringNotContainsString('fake-signed-qr', $pdf);
        $this->assertStringNotContainsString('eyJhbGciOiJFUzI1NiJ9', $pdf);
        $this->assertStringNotContainsString('% signed-qr-image', $pdf);
        $this->assertStringNotContainsString('Signed QR issued with this IRN.', $pdf);
    }

    public function test_irn_with_jwt_signed_qr_draws_a_visual_qr_and_keeps_ack(): void
    {
        $jwt = $this->jwtSignedQr();
        $pdf = (new SimplePdfRenderer)->render(new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-076749',
            issuedAt: '2026-09-10 17:20:00',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: '1312, Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            buyerName: 'Phil Technologies (P) Limited',
            buyerGstin: '27AAICP1128M1Z7',
            billingAddress: 'G40, Harmony Mall, Link Road, Goregaon',
            placeOfSupply: 'Maharashtra',
            lines: [[
                'description' => 'Mantra MFS 110 L1 Single Fingerprint Biometric Scanner',
                'hsnSac' => '84716050',
                'qty' => 1,
                'unitPrice' => '2117.80',
                'taxableValue' => '2117.80',
                'gstPercentage' => '18.00%',
                'cgst' => '0.00',
                'sgst' => '0.00',
                'igst' => '381.20',
                'taxTotal' => '381.20',
                'lineTotal' => '2499.00',
                'uqc' => 'PCS',
            ]],
            taxableValue: '2117.80',
            gstRate: '18.00%',
            taxTotal: '381.20',
            cgst: '0.00',
            sgst: '0.00',
            igst: '381.20',
            invoiceValue: '2499.00',
            irn: 'd858cf9f0a582a7aec79eade603e66cf584e9a68bdde5a50a823793527a9d776',
            ackNo: '172621145994081',
            ackDate: '2026-09-10 17:21:00',
            signedQr: $jwt,
        ));
        $text = $this->text($pdf);

        $this->assertStringContainsString('% signed-qr-image', $pdf);
        $this->assertStringContainsString('IRN', $text);
        $this->assertStringContainsString('d858cf9f0a582a7aec79eade603e66cf584e9a68bdde5a50a823793527a9d776', $text);
        $this->assertStringContainsString('Ack No: 172621145994081', $text);
        $this->assertStringContainsString('e-Invoice Verification', $text);
        $this->assertStringContainsString('Rs.2499.00', $text);
        $this->assertStringContainsString('84716050', $text);
        $this->assertStringContainsString('PCS', $text);
        $this->assertStringNotContainsString('Signed QR issued with this IRN.', $pdf);
        $this->assertStringNotContainsString($jwt, $pdf);
        $this->assertStringContainsString('/Logo Do', $pdf);
    }

    public function test_irn_with_production_length_jwt_draws_qr_without_leaking_payload(): void
    {
        $header = 'eyJhbGciOiJFUzI1NiIsInR5cCI6IkpXVCJ9';
        $jwt = $header.str_repeat('A', 150 - strlen($header)).'.'.str_repeat('B', 450).'.'.str_repeat('C', 342);
        $pdf = (new SimplePdfRenderer)->render($this->payload(
            irn: 'd858cf9f0a582a7aec79eade603e66cf584e9a68bdde5a50a823793527a9d776',
            ackNo: '172621145994081',
            ackDate: '2026-09-10 17:21:00',
            signedQr: $jwt,
        ));

        $this->assertSame(944, strlen($jwt));
        $this->assertStringContainsString('% signed-qr-image', $pdf);
        $this->assertStringContainsString('Ack No: 172621145994081', $this->text($pdf));
        $this->assertStringNotContainsString('Signed QR issued with this IRN.', $pdf);
        $this->assertStringNotContainsString($jwt, $pdf);
        $this->assertStringNotContainsString(str_repeat('B', 32), $pdf);
    }

    public function test_irn_with_invalid_signed_qr_falls_back_to_caption_without_empty_qr(): void
    {
        $pdf = (new SimplePdfRenderer)->render($this->payload(
            irn: 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
            ackNo: 'ACK-1001',
            ackDate: '2026-09-07 18:40:00',
            signedQr: 'not-a-jwt',
        ));
        $text = $this->text($pdf);

        $this->assertStringContainsString('IRN', $text);
        $this->assertStringContainsString('Ack No: ACK-1001', $text);
        $this->assertStringContainsString('Signed QR issued with this IRN.', $pdf);
        $this->assertStringNotContainsString('% signed-qr-image', $pdf);
        $this->assertStringNotContainsString('not-a-jwt', $pdf);
    }

    public function test_irn_without_signed_qr_does_not_emit_qr_or_caption(): void
    {
        $pdf = (new SimplePdfRenderer)->render($this->payload(
            irn: 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
            ackNo: 'ACK-1001',
            ackDate: '2026-09-07 18:40:00',
        ));

        $this->assertStringContainsString('IRN', $this->text($pdf));
        $this->assertStringNotContainsString('% signed-qr-image', $pdf);
        $this->assertStringNotContainsString('Signed QR issued with this IRN.', $pdf);
    }

    public function test_presentation_rewrite_keeps_financial_totals(): void
    {
        $invoice = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD-PDF-REGEN'),
            $this->actor,
        );
        $before = $invoice->fresh();
        $first = $this->text($this->pdf($invoice->id));

        app(StatutoryDocumentService::class)->regeneratePresentation($invoice->fresh(['items', 'eInvoiceRecord']));
        $after = $invoice->fresh();
        $second = $this->text($this->pdf($invoice->id));

        $this->assertSame((string) $before->invoice_number, (string) $after->invoice_number);
        $this->assertSame((string) $before->taxable_value, (string) $after->taxable_value);
        $this->assertSame((string) $before->tax_total, (string) $after->tax_total);
        $this->assertSame((string) $before->invoice_value, (string) $after->invoice_value);
        $this->assertStringContainsString('Rs.'.number_format((float) $before->invoice_value, 2, '.', ''), $first);
        $this->assertStringContainsString('Rs.'.number_format((float) $after->invoice_value, 2, '.', ''), $second);
        $this->assertStringContainsString($before->invoice_number, $second);
    }

    public function test_commerce_pdf_uses_order_shipping_and_payment_method(): void
    {
        $order = $this->commerceOrder('RD-PDF-SHIP');
        $order->forceFill([
            'shipping_address' => 'Warehouse Gate 2, Andheri East, Mumbai 400069',
            'payment_method' => 'UPI',
        ])->save();

        $invoice = $this->invoices->issueFromCommerceOrder($order->fresh(), $this->actor);
        $pdf = $this->text($this->pdf($invoice->id));

        $this->assertStringContainsString('SHIP TO', $pdf);
        $this->assertStringContainsString('Andheri East', $pdf);
        $this->assertStringContainsString('UPI', $pdf);
        $this->assertStringNotContainsString('IRN not submitted', $pdf);
    }

    public function test_pdf_embeds_authoritative_brand_logo(): void
    {
        $binary = (new SimplePdfRenderer)->render($this->payload());
        $text = $this->text($binary);

        $this->assertFileExists(public_path('brand/logo.svg'));
        $this->assertStringContainsString('/Logo Do', $binary);
        $this->assertStringContainsString('DCTDecode', $binary);
        $this->assertStringContainsString('TAX INVOICE', $text);
        $this->assertStringNotContainsString('ADIUM', $text);
    }

    public function test_pdf_embeds_authoritative_stamp_and_signatory_block(): void
    {
        $binary = (new SimplePdfRenderer)->render($this->payload());
        $text = $this->text($binary);

        $this->assertFileExists(public_path('brand/stamp-bgr.png'));
        $this->assertStringContainsString('/Stamp Do', $binary);
        $this->assertStringContainsString('Authorized Signatory', $text);
        $this->assertStringContainsString('For Phil Technologies (P) Limited', $text);
    }

    public function test_missing_logo_asset_fails_clearly_without_legacy_fallback(): void
    {
        $logoPath = public_path('brand/logo.svg');
        $backup = $logoPath.'.p224-backup';
        $this->assertTrue(rename($logoPath, $backup));

        try {
            $this->expectException(StatutoryInvoicePdfAssetException::class);
            $this->expectExceptionMessage('brand/logo.svg');

            (new SimplePdfRenderer)->render($this->payload());
        } finally {
            rename($backup, $logoPath);
        }
    }

    public function test_missing_stamp_asset_fails_clearly(): void
    {
        $stampPath = public_path('brand/stamp-bgr.png');
        $backup = $stampPath.'.p224-backup';
        $this->assertTrue(rename($stampPath, $backup));

        try {
            $this->expectException(StatutoryInvoicePdfAssetException::class);
            $this->expectExceptionMessage('brand/stamp-bgr.png');

            (new SimplePdfRenderer)->render($this->payload());
        } finally {
            rename($backup, $stampPath);
        }
    }

    public function test_signed_qr_at_64px_decodes_from_rendered_pdf_when_zbar_available(): void
    {
        $zbar = null;
        foreach (['/opt/homebrew/bin/zbarimg', '/usr/local/bin/zbarimg', '/usr/bin/zbarimg'] as $candidate) {
            if (is_executable($candidate)) {
                $zbar = $candidate;
                break;
            }
        }
        if ($zbar === null) {
            $this->markTestSkipped('zbarimg unavailable for QR decode verification.');
        }

        $jwt = $this->jwtSignedQr();
        $pdfPath = storage_path('framework/testing/invoice-qr-decode.pdf');
        file_put_contents($pdfPath, (new SimplePdfRenderer)->render($this->payload(
            irn: 'issued-irn-token-0001',
            ackNo: '112233',
            ackDate: '07 Sep 2026 18:40',
            signedQr: $jwt,
        )));
        $pngPrefix = storage_path('framework/testing/invoice-qr-decode');
        @unlink($pngPrefix.'-1.png');
        $pdftoppm = null;
        foreach (['/opt/homebrew/bin/pdftoppm', '/usr/local/bin/pdftoppm', '/usr/bin/pdftoppm'] as $candidate) {
            if (is_executable($candidate)) {
                $pdftoppm = $candidate;
                break;
            }
        }
        if ($pdftoppm === null) {
            $this->markTestSkipped('pdftoppm unavailable for QR decode verification.');
        }

        $command = escapeshellarg($pdftoppm).' -png -r 180 '.escapeshellarg($pdfPath).' '.escapeshellarg($pngPrefix);
        exec($command.' 2>/dev/null', $output, $exitCode);
        $pngFile = $pngPrefix.'-1.png';
        $this->assertSame(0, $exitCode);
        $this->assertFileExists($pngFile);

        $decodeCommand = escapeshellarg($zbar).' --raw -q '.escapeshellarg($pngFile);
        $decoded = trim((string) shell_exec($decodeCommand.' 2>/dev/null'));
        $this->assertSame($jwt, $decoded);
    }

    public function test_pdf_uses_true_a4_dimensions(): void
    {
        $binary = (new SimplePdfRenderer)->render($this->payload());

        $this->assertStringContainsString('/MediaBox [0 0 595 842]', $binary);
    }

    public function test_inv_076730_style_hardware_invoice_layout(): void
    {
        $jwt = $this->jwtSignedQr();
        $binary = (new SimplePdfRenderer)->render(new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-076730',
            issuedAt: '2026-09-09 12:00:00',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: '1312, Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            sellerEmail: 'mail@radiumbox.com',
            sellerPhone: '+91-84343 84343',
            buyerName: 'vijay thakur',
            buyerGstin: '02ADZPT2982Q1ZD',
            billingAddress: 'Global Computer Solutions, lower bazar sarkaghat tehsil sarkaghat dist mandi 175024, opposite uco bank, Mandi, Himachal Pradesh, 175024',
            buyerEmail: 'csc.sarkaghart175024@gmail.com',
            buyerPhone: '9816004621',
            placeOfSupply: 'Himachal Pradesh',
            lines: [[
                'description' => 'Mantra MFS 100 / 110 L1 Fingerprint Scanner (bundled RD #1119)',
                'hsnSac' => '84716050',
                'qty' => 1,
                'unitPrice' => '2499.00',
                'taxableValue' => '2117.80',
                'gstPercentage' => '18.00%',
                'cgst' => '0.00',
                'sgst' => '0.00',
                'igst' => '381.20',
                'taxTotal' => '381.20',
                'lineTotal' => '2499.00',
                'uqc' => '-',
            ]],
            taxableValue: '2117.80',
            gstRate: '18.00%',
            taxTotal: '381.20',
            cgst: '0.00',
            sgst: '0.00',
            igst: '381.20',
            invoiceValue: '2499.00',
            serialNumbers: ['10532500'],
            orderId: 'RDE318500',
            paymentReference: '6859184740',
            paymentMethod: 'cashfree',
            irn: 'b24bf9cba16f8698baf6983aa7594621c1862c6bc84162cddcaf6da73287ec8',
            ackNo: '172621148747086',
            ackDate: '2026-09-10 23:18:00',
            signedQr: $jwt,
        ));
        $text = $this->text($binary);

        $this->assertStringContainsString('INV-076730', $text);
        $this->assertStringContainsString('RDE318500', $text);
        $this->assertStringContainsString('b24bf9cba16f8698baf6983aa7594621c1862c6bc84162cddcaf6da73287ec8', $text);
        $this->assertStringContainsString('Ack No: 172621148747086', $text);
        $this->assertStringContainsString('Date: 10 Sep 2026 23:18', $text);
        $this->assertStringContainsString('Rs.2117.80', $text);
        $this->assertStringContainsString('Rs.381.20', $text);
        $this->assertStringContainsString('Rs.2499.00', $text);
        $this->assertStringContainsString('Two Thousand Four Hundred Ninety-Nine Rupees Only', $text);
        $this->assertStringContainsString('10532500', $text);
        $this->assertStringContainsString('% signed-qr-image', $binary);
        $this->assertStringContainsString('e-Invoice Verification', $text);
        $this->assertStringContainsString('Whether tax is payable on reverse charge basis: No', $text);
    }

    public function test_five_serial_numbers_fit_on_first_page_without_annexure(): void
    {
        $serials = ['10532500', '10532501', '10532502', '10532503', '10532504'];
        $text = $this->text((new SimplePdfRenderer)->render(new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-076731',
            issuedAt: '2026-09-09 12:00:00',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: '1312, Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            buyerName: 'Hardware Buyer',
            buyerGstin: '07AAAAA0000A1Z5',
            billingAddress: '1 Test Street, Delhi',
            placeOfSupply: 'Delhi',
            lines: [[
                'description' => 'Mantra MFS 110 L1',
                'hsnSac' => '84716050',
                'qty' => 5,
                'unitPrice' => '100.00',
                'taxableValue' => '500.00',
                'gstPercentage' => '18.00%',
                'cgst' => '45.00',
                'sgst' => '45.00',
                'igst' => '0.00',
                'taxTotal' => '90.00',
                'lineTotal' => '590.00',
            ]],
            taxableValue: '500.00',
            gstRate: '18.00%',
            taxTotal: '90.00',
            cgst: '45.00',
            sgst: '45.00',
            igst: '0.00',
            invoiceValue: '590.00',
            serialNumbers: $serials,
        )));

        $this->assertStringContainsString('Serial Numbers', $text);
        foreach ($serials as $serial) {
            $this->assertStringContainsString($serial, $text);
        }
        $this->assertStringNotContainsString('ANNEXURE A', $text);
    }

    public function test_company_cin_is_printed_in_seller_block(): void
    {
        $text = $this->text((new SimplePdfRenderer)->render($this->payload()));

        $this->assertStringContainsString('CIN: U72300DL2015PTC280283', $text);
        $this->assertStringContainsString('GSTIN 27AAICP1128M1Z7', $text);
        $this->assertStringContainsString('/Logo Do', (new SimplePdfRenderer)->render($this->payload()));
    }

    public function test_long_product_name_stays_in_product_column(): void
    {
        $text = $this->text((new SimplePdfRenderer)->render(new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-LONG-SKU',
            issuedAt: '2026-09-09 12:00:00',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: '1312, Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            buyerName: 'Hardware Buyer',
            buyerGstin: '07AAAAA0000A1Z5',
            billingAddress: '1 Test Street, Delhi',
            placeOfSupply: 'Delhi',
            lines: [[
                'description' => 'Mantra MFS 100 / 110 L1 Fingerprint Scanner (bundled RD #1119) with an unusually long commercial description that must wrap inside the product column',
                'hsnSac' => '84716050',
                'qty' => 1,
                'unitPrice' => '2117.80',
                'taxableValue' => '2117.80',
                'gstPercentage' => '18.00%',
                'cgst' => '0.00',
                'sgst' => '0.00',
                'igst' => '381.20',
                'taxTotal' => '381.20',
                'lineTotal' => '2499.00',
                'uqc' => 'PCS',
            ]],
            taxableValue: '2117.80',
            gstRate: '18.00%',
            taxTotal: '381.20',
            cgst: '0.00',
            sgst: '0.00',
            igst: '381.20',
            invoiceValue: '2499.00',
        )));

        $this->assertStringContainsString('Mantra MFS 100', $text);
        $this->assertStringContainsString('Fingerprint', $text);
        $this->assertStringContainsString('Scanner', $text);
        $this->assertStringContainsString('84716050', $text);
        $this->assertStringContainsString('Tax Rate', $text);
        $this->assertStringContainsString('Rs.2117.80', $text);
        $this->assertStringContainsString('Rs.2499.00', $text);
    }

    public function test_twenty_four_serials_keep_complete_unique_annexure(): void
    {
        $serials = [];
        for ($i = 1; $i <= 24; $i++) {
            $serials[] = sprintf('SN-%02d', $i);
        }

        $pdf = $this->text((new SimplePdfRenderer)->render(new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-076740',
            issuedAt: '2026-09-09 12:00:00',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: '1312, Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            buyerName: 'Hardware Buyer',
            buyerGstin: '07AAAAA0000A1Z5',
            billingAddress: '1 Test Street, Delhi',
            placeOfSupply: 'Delhi',
            lines: [[
                'description' => 'Mantra MFS 110 L1',
                'hsnSac' => '84716050',
                'qty' => 24,
                'unitPrice' => '100.00',
                'taxableValue' => '2400.00',
                'gstPercentage' => '18.00%',
                'cgst' => '216.00',
                'sgst' => '216.00',
                'igst' => '0.00',
                'taxTotal' => '432.00',
                'lineTotal' => '2832.00',
                'uqc' => 'PCS',
            ]],
            taxableValue: '2400.00',
            gstRate: '18.00%',
            taxTotal: '432.00',
            cgst: '216.00',
            sgst: '216.00',
            igst: '0.00',
            invoiceValue: '2832.00',
            serialNumbers: $serials,
            orderId: 'RDE318900',
        )));

        $this->assertStringNotContainsString('ANNEXURE A', $pdf);
        $this->assertStringNotContainsString('* More serial numbers in Annexure A', $pdf);
        foreach ($serials as $serial) {
            $this->assertStringContainsString($serial, $pdf);
        }
        $this->assertSame(1, substr_count($pdf, 'SN-24'));
        $this->assertStringContainsString('Rs.2832.00', $pdf);
        $this->assertStringContainsString('Page 1 of', $pdf);
    }

    public function test_fifty_serials_fit_on_page_one_without_annexure(): void
    {
        $serials = [];
        for ($i = 1; $i <= 50; $i++) {
            $serials[] = sprintf('SN-%03d', $i);
        }

        $pdf = $this->text((new SimplePdfRenderer)->render(new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-076850',
            issuedAt: '2026-09-11 12:00:00',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: '1312, Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            buyerName: 'Hardware Buyer',
            buyerGstin: '07AAAAA0000A1Z5',
            billingAddress: '1 Test Street, Delhi',
            placeOfSupply: 'Delhi',
            lines: [[
                'description' => 'Mantra MFS 110 L1',
                'hsnSac' => '84716050',
                'qty' => 50,
                'unitPrice' => '100.00',
                'taxableValue' => '5000.00',
                'gstPercentage' => '18.00%',
                'cgst' => '450.00',
                'sgst' => '450.00',
                'igst' => '0.00',
                'taxTotal' => '900.00',
                'lineTotal' => '5900.00',
                'uqc' => 'PCS',
            ]],
            taxableValue: '5000.00',
            gstRate: '18.00%',
            taxTotal: '900.00',
            cgst: '450.00',
            sgst: '450.00',
            igst: '0.00',
            invoiceValue: '5900.00',
            serialNumbers: $serials,
            orderId: 'POS-000050',
        )));

        $this->assertStringContainsString('1. SN-001', $pdf);
        $this->assertStringContainsString('50. SN-050', $pdf);
        $this->assertStringNotContainsString('ANNEXURE A', $pdf);
        $this->assertStringNotContainsString('* More serial numbers in Annexure A', $pdf);
        $this->assertStringContainsString('SHIP TO', $pdf);
        $this->assertStringContainsString('Same', $pdf);
    }

    public function test_fifty_one_serials_put_remainder_only_in_annexure(): void
    {
        $serials = [];
        for ($i = 1; $i <= 51; $i++) {
            $serials[] = sprintf('SN-%03d', $i);
        }

        $pdf = $this->text((new SimplePdfRenderer)->render(new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-076851',
            issuedAt: '2026-09-11 12:00:00',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: '1312, Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            buyerName: 'Hardware Buyer',
            buyerGstin: '07AAAAA0000A1Z5',
            billingAddress: '1 Test Street, Delhi',
            placeOfSupply: 'Delhi',
            lines: [[
                'description' => 'Mantra MFS 110 L1',
                'hsnSac' => '84716050',
                'qty' => 51,
                'unitPrice' => '100.00',
                'taxableValue' => '5100.00',
                'gstPercentage' => '18.00%',
                'cgst' => '459.00',
                'sgst' => '459.00',
                'igst' => '0.00',
                'taxTotal' => '918.00',
                'lineTotal' => '6018.00',
                'uqc' => 'PCS',
            ]],
            taxableValue: '5100.00',
            gstRate: '18.00%',
            taxTotal: '900.00',
            cgst: '459.00',
            sgst: '459.00',
            igst: '0.00',
            invoiceValue: '6018.00',
            serialNumbers: $serials,
            orderId: 'POS-000051',
        )));

        $this->assertStringContainsString('* More serial numbers in Annexure A', $pdf);
        $this->assertStringContainsString('ANNEXURE A', $pdf);
        $this->assertStringContainsString('51. SN-051', $pdf);
        $this->assertSame(1, substr_count($pdf, 'SN-001'));
        $this->assertSame(1, substr_count($pdf, 'SN-051'));
    }

    public function test_one_hundred_serials_are_never_truncated(): void
    {
        $serials = [];
        for ($i = 1; $i <= 100; $i++) {
            $serials[] = sprintf('SN-%03d', $i);
        }

        $pdf = $this->text((new SimplePdfRenderer)->render(new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-076900',
            issuedAt: '2026-09-11 12:00:00',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: '1312, Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            buyerName: 'Hardware Buyer',
            buyerGstin: '07AAAAA0000A1Z5',
            billingAddress: '1 Test Street, Delhi',
            placeOfSupply: 'Delhi',
            lines: [[
                'description' => 'Mantra MFS 110 L1',
                'hsnSac' => '84716050',
                'qty' => 100,
                'unitPrice' => '100.00',
                'taxableValue' => '10000.00',
                'gstPercentage' => '18.00%',
                'cgst' => '900.00',
                'sgst' => '900.00',
                'igst' => '0.00',
                'taxTotal' => '1800.00',
                'lineTotal' => '11800.00',
                'uqc' => 'PCS',
            ]],
            taxableValue: '10000.00',
            gstRate: '18.00%',
            taxTotal: '1800.00',
            cgst: '900.00',
            sgst: '900.00',
            igst: '0.00',
            invoiceValue: '11800.00',
            serialNumbers: $serials,
            rounding: '0.00',
            orderId: 'POS-000100',
        )));

        $this->assertStringContainsString('ANNEXURE A', $pdf);
        foreach ($serials as $serial) {
            $this->assertStringContainsString($serial, $pdf);
        }
        $this->assertSame(1, substr_count($pdf, 'SN-050'));
        $this->assertSame(1, substr_count($pdf, 'SN-100'));
    }

    public function test_unpaid_payment_does_not_print_a_receipt_date(): void
    {
        $pdf = $this->text((new SimplePdfRenderer)->render(new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-076860',
            issuedAt: '2026-09-11 12:00:00',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: '1312, Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            buyerName: 'ABC MOBILE MART',
            buyerGstin: '09ANQPA2385P1ZB',
            billingAddress: 'B18 BUTLER PLAZA, BAREILLY',
            placeOfSupply: 'Uttar Pradesh',
            lines: [[
                'description' => 'Mantra MFS 110 L1',
                'hsnSac' => '84716050',
                'qty' => 1,
                'unitPrice' => '100.00',
                'taxableValue' => '100.00',
                'gstPercentage' => '18.00%',
                'cgst' => '9.00',
                'sgst' => '9.00',
                'igst' => '0.00',
                'taxTotal' => '18.00',
                'lineTotal' => '118.00',
                'uqc' => 'PCS',
            ]],
            taxableValue: '100.00',
            gstRate: '18.00%',
            taxTotal: '18.00',
            cgst: '9.00',
            sgst: '9.00',
            igst: '0.00',
            invoiceValue: '118.00',
            paymentMethod: 'Bank Transfer',
            paymentStatus: 'UNPAID',
            rounding: '0.06',
        )));

        $this->assertStringContainsString('UNPAID', $pdf);
        $this->assertStringContainsString('Bank Transfer', $pdf);
        $this->assertStringNotContainsString('Payment Date', $pdf);
        $this->assertStringContainsString('Round Off', $pdf);
    }

    public function test_b2c_single_page_omits_empty_ship_to_and_einvoice_block(): void
    {
        $binary = (new SimplePdfRenderer)->render($this->payload());
        $text = $this->text($binary);

        $this->assertStringContainsString('/MediaBox [0 0 595 842]', $binary);
        $this->assertStringContainsString('Page 1 of 1', $text);
        $this->assertStringContainsString('SHIP TO', $text);
        $this->assertStringContainsString('Same', $text);
        $this->assertStringNotContainsString('e-Invoice Verification', $text);
        $this->assertStringContainsString('GSTIN Unregistered', $text);
        $this->assertStringContainsString('CIN: U72300DL2015PTC280283', $text);
    }

    private function pdf(int $invoiceId): string
    {
        $document = StatutoryInvoiceDocument::query()->where('invoice_id', $invoiceId)->firstOrFail();

        return app(StatutoryDocumentService::class)->binary($document);
    }

    private function text(string $pdf): string
    {
        return str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $pdf);
    }

    private function regenerate(int $invoiceId): void
    {
        $document = StatutoryInvoiceDocument::query()->where('invoice_id', $invoiceId)->firstOrFail();
        Storage::disk($document->disk ?: 'local')->delete((string) $document->path);
        $document->forceFill([
            'status' => StatutoryInvoiceDocumentStatus::Failed,
            'path' => null,
        ])->save();

        $invoice = StatutoryInvoice::query()->with(['items', 'eInvoiceRecord'])->findOrFail($invoiceId);
        app(StatutoryDocumentService::class)->generate($invoice);
    }

    private function commerceOrder(
        string $sourceId,
        ?string $buyerGstin = null,
        string $description = 'Information technology (IT) consulting & support services',
        ?string $companion = null,
        StatutoryInvoiceChannel $channel = StatutoryInvoiceChannel::RdServiceIn,
        string $hsnSac = '998314',
    ): CommerceOrder {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => $channel,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'idempotency_key' => 'statutory:'.$channel->value.':commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'CHANDRAKANT GANPAT SARODE',
            'buyer_gstin' => $buyerGstin,
            'billing_state' => 'Maharashtra',
            'billing_address' => '312 dhamankar plaza Dhamankar naka Bhiwandi, Thane, Maharashtra, 421305',
            'branch_code' => 'MUMBAI',
            'place_of_supply_state' => 'Maharashtra',
            'taxable_value' => 422.88,
            'tax_total' => 76.12,
            'order_value' => 499.00,
            'ordered_at' => '2026-09-07 10:00:00',
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'description' => $description,
            'hsn_sac' => $hsnSac,
            'qty' => 1,
            'unit_price' => 422.88,
            'gst_percentage' => 18,
            'taxable_value' => 422.88,
            'tax_total' => 76.12,
            'line_total' => 499.00,
        ]);
        if ($companion !== null) {
            $order->items()->create([
                'line_no' => 2,
                'description' => $companion,
                'hsn_sac' => $hsnSac,
                'qty' => 1,
                'unit_price' => 0,
                'gst_percentage' => 18,
                'taxable_value' => 0,
                'tax_total' => 0,
                'line_total' => 0,
            ]);
        }

        return $order->fresh(['items']);
    }

    private function jwtSignedQr(): string
    {
        return 'eyJhbGciOiJFUzI1NiIsInR5cCI6IkpXVCJ9.eyJkYXRhIjoiZWluaXZvaWNlLXRlc3QtcGF5bG9hZC1maXh0dXJlIn0.dGVzdC1zaWduYXR1cmUtZml4dHVyZS1ub3QtcHJvZHVjdGlvbg';
    }

    private function payload(
        ?string $irn = null,
        ?string $ackNo = null,
        ?string $ackDate = null,
        ?string $signedQr = null,
    ): StatutoryInvoicePdfPayload {
        return new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-276710',
            issuedAt: '2026-09-07 18:28:19',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '27AAICP1128M1Z7',
            sellerAddress: 'G40, Harmony Mall, Link Road, Goregaon, Mumbai 400104',
            sellerState: 'Maharashtra',
            buyerName: 'CHANDRAKANT GANPAT SARODE',
            buyerGstin: null,
            billingAddress: '312 dhamankar plaza, Bhiwandi',
            placeOfSupply: 'Maharashtra',
            lines: [[
                'description' => 'Information technology (IT) consulting & support services (SAC - 998313)',
                'hsnSac' => '998314',
                'qty' => 1,
                'unitPrice' => '422.88',
                'taxableValue' => '422.88',
                'gstPercentage' => '18.00%',
                'cgst' => '38.06',
                'sgst' => '38.06',
                'igst' => '0.00',
                'taxTotal' => '76.12',
                'lineTotal' => '499.00',
            ]],
            taxableValue: '422.88',
            gstRate: '18.00%',
            taxTotal: '76.12',
            cgst: '38.06',
            sgst: '38.06',
            igst: '0.00',
            invoiceValue: '499.00',
            irn: $irn,
            ackNo: $ackNo,
            ackDate: $ackDate,
            signedQr: $signedQr,
        );
    }
}
