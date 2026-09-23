<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\StatutoryInvoiceChannel;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\StatutoryInvoice\Data\StatutoryInvoicePdfPayload;
use App\Services\StatutoryInvoice\SimplePdfRenderer;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AssertsStatutoryInvoicePdfSerials;
use Tests\TestCase;

class StatutoryInvoicePdfPaginationTest extends TestCase
{
    use AssertsStatutoryInvoicePdfSerials;
    use RefreshDatabase;

    private const IRN = '127af3ac3ce92b18f7df76e2722cf935d24cfe90322cffa501261794a17f4ac2';

    private User $actor;

    private InventoryBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-22 15:00:00');
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
        ]);

        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_main_footer_remains_on_first_invoice_page_with_serial_preview(): void
    {
        $serials = $this->serialList('SN-FOOTER', 8);
        $binary = (new SimplePdfRenderer)->render($this->basePayload(
            serialNumbers: $serials,
            serialGroups: [[
                'label' => 'RBMFS110L1 — Mantra MFS 110 L1',
                'serials' => $serials,
            ]],
            withIrn: true,
            withPayment: true,
        ));

        $this->assertMainInvoiceFooterOnFirstPage($binary, self::IRN);
        $this->assertStringContainsString('Payment Details', $this->mainPageExtractedText($this->extractedPdfText($binary)));
        $this->assertNoClosingOnlyContinuationPage($binary);
    }

    public function test_inv_0767211_fixture_keeps_statutory_footer_on_main_page_with_annexure(): void
    {
        $modelASerials = $this->inv0767211ModelASerials();
        $modelBSerials = $this->inv0767211ModelBSerials();
        $binary = (new SimplePdfRenderer)->render($this->inv0767211Payload($modelASerials, $modelBSerials));
        $extracted = $this->extractedPdfText($binary);
        $main = $this->mainPageExtractedText($extracted);

        $this->assertMainInvoiceFooterOnFirstPage($binary, self::IRN);
        $this->assertStringContainsString('Payment Details', $this->firstMainInvoicePageText($extracted));
        $this->assertStringContainsString(SimplePdfRenderer::ANNEXURE_NOTICE_TEXT, $main);
        $this->assertGroupedAnnexureComplete($binary, [
            'Mantra MFS 110 L1' => $modelASerials,
            'Access FM220 USB L1' => $modelBSerials,
        ], 105);
        $this->assertPage1HasZeroInlineSerials($binary, array_merge($modelASerials, $modelBSerials));
        $this->assertSame(2, $this->pdfPageCount($binary), 'INV-0767211 should use one main page and one packed Annexure page.');
        $this->assertSame(1, $this->annexurePageCount($binary));
        $this->assertStringNotContainsString('ANNEXURE A (continued)', $this->extractedPdfText($binary));
    }

    public function test_annexure_packs_small_second_group_after_large_first_group_on_same_page(): void
    {
        $modelASerials = $this->serialList('PACK-A', 100);
        $modelBSerials = $this->serialList('PACK-B', 5);
        $binary = (new SimplePdfRenderer)->render($this->multiGroupPayload(
            modelA: $modelASerials,
            modelB: $modelBSerials,
            withIrn: true,
        ));

        $this->assertSame(2, $this->pdfPageCount($binary));
        $annexure = $this->annexureExtractedText($this->extractedPdfText($binary));
        $this->assertStringContainsString('Mantra MFS 110 L1', $annexure);
        $this->assertStringContainsString('Access FM220 USB L1', $annexure);
        $this->assertStringNotContainsString('ANNEXURE A (continued)', $this->extractedPdfText($binary));
        $this->assertGroupedAnnexureComplete($binary, [
            'Mantra MFS 110 L1' => $modelASerials,
            'Access FM220 USB L1' => $modelBSerials,
        ], 105);
    }

    public function test_annexure_splits_across_pages_when_capacity_is_exceeded(): void
    {
        $serials = $this->serialList('CAP', 260);
        $binary = (new SimplePdfRenderer)->render($this->basePayload(
            serialNumbers: $serials,
            serialGroups: [[
                'label' => 'RBMFS110L1 — Mantra MFS 110 L1',
                'serials' => $serials,
            ]],
            withIrn: true,
        ));

        $this->assertGreaterThan(2, $this->pdfPageCount($binary));
        $this->assertGreaterThan(1, $this->annexurePageCount($binary));
        $this->assertStringContainsString('ANNEXURE A (continued)', $this->extractedPdfText($binary));
        $this->assertOptionBWithAnnexure($binary, $serials);
        $this->assertMainInvoiceFooterOnFirstPage($binary, self::IRN);
    }

    public function test_service_single_line_invoice_uses_compact_closing_layout(): void
    {
        $binary = (new SimplePdfRenderer)->render($this->inv0767220Payload());
        $extracted = $this->extractedPdfText($binary);

        $this->assertSame(1, $this->pdfPageCount($binary));
        $this->assertStringContainsString('Secondary Freight Reverse Auction', $extracted);
        $this->assertStringContainsString('PO Number', $extracted);
        $this->assertStringContainsString('ATFL/MAN/15374', $extracted);
        $this->assertStringContainsString('TOTAL INVOICE VALUE', $extracted);
        $this->assertStringContainsString('Authorized Signatory', $extracted);
        $this->assertStringNotContainsString('ANNEXURE A', $extracted);

        $amountInWordsY = $this->pdfWordYMin($binary, 'words');
        $this->assertGreaterThan(250.0, $amountInWordsY, 'Closing block should remain in the Page-1 footer reservation.');
        $this->assertLessThan(620.0, $amountInWordsY, 'Closing block should remain within the reserved Page-1 footer region.');
    }

    public function test_small_serial_set_fits_on_main_page_without_annexure(): void
    {
        $serials = $this->serialList('SN-SMALL', 6);
        $binary = (new SimplePdfRenderer)->render($this->basePayload(
            serialNumbers: $serials,
            serialGroups: [[
                'label' => 'RBMFS110L1 — Mantra MFS 110 L1',
                'serials' => $serials,
            ]],
            withIrn: true,
        ));

        $this->assertOptionBMainPageOnly($binary, $serials);
        $this->assertMainInvoiceFooterOnFirstPage($binary, self::IRN);
    }

    public function test_multi_model_small_serial_set_has_no_annexure_and_footer_on_main_page(): void
    {
        $modelASerials = $this->serialList('MFS110', 5);
        $modelBSerials = $this->serialList('FM220', 5);
        $binary = (new SimplePdfRenderer)->render($this->multiGroupPayload(
            modelA: $modelASerials,
            modelB: $modelBSerials,
            withIrn: true,
        ));
        $extracted = $this->extractedPdfText($binary);

        $this->assertStringNotContainsString('ANNEXURE A', $extracted);
        $this->assertSerialsPresentInExtractedText($extracted, array_merge($modelASerials, $modelBSerials), 'Main page');
        $this->assertMainInvoiceFooterOnFirstPage($binary, self::IRN);
    }

    public function test_high_volume_serials_keep_footer_on_main_invoice_page(): void
    {
        $serials = $this->serialList('SN-HIGH', 120);
        $binary = (new SimplePdfRenderer)->render($this->basePayload(
            serialNumbers: $serials,
            withIrn: true,
            withPayment: true,
        ));

        $this->assertMainInvoiceFooterOnFirstPage($binary, self::IRN);
        $this->assertOptionBWithAnnexure($binary, $serials);
        $this->assertNoClosingOnlyContinuationPage($binary);
    }

    public function test_multi_model_one_hundred_plus_five_annexure_totals_one_hundred_five(): void
    {
        $modelASerials = $this->serialList('MFS110', 100);
        $modelBSerials = $this->serialList('FM220', 5);
        $binary = (new SimplePdfRenderer)->render($this->multiGroupPayload(
            modelA: $modelASerials,
            modelB: $modelBSerials,
            withIrn: true,
        ));

        $this->assertGroupedAnnexureComplete($binary, [
            'Mantra MFS 110 L1' => $modelASerials,
            'Access FM220 USB L1' => $modelBSerials,
        ], 105);
        $this->assertMainInvoiceFooterOnFirstPage($binary, self::IRN);
    }

    public function test_irn_and_acknowledgement_remain_on_main_invoice_page(): void
    {
        $binary = (new SimplePdfRenderer)->render($this->inv0767211Payload(
            $this->inv0767211ModelASerials(),
            $this->inv0767211ModelBSerials(),
        ));
        $main = $this->mainPageExtractedText($this->extractedPdfText($binary));

        $this->assertStringContainsString(self::IRN, $main);
        $this->assertStringContainsString('172621230160465', $main);
        $this->assertStringContainsString('e-Invoice Verification', $main);
    }

    public function test_customer_facing_sku_prefixes_remain_removed_after_pagination_fix(): void
    {
        $binary = (new SimplePdfRenderer)->render($this->inv0767211Payload(
            $this->inv0767211ModelASerials(),
            $this->inv0767211ModelBSerials(),
        ));
        $text = $this->extractedPdfText($binary);

        $this->assertStringContainsString('Mantra MFS 110 L1', $text);
        $this->assertStringContainsString('Access FM220 USB L1', $text);
        $this->assertStringNotContainsString('RBMFS110L1', $text);
        $this->assertStringNotContainsString('RBFM220UFP', $text);
    }

    public function test_service_pos_po_number_regression_below_order_id(): void
    {
        $binary = (new SimplePdfRenderer)->render($this->basePayload(
            channel: StatutoryInvoiceChannel::DeskService->value,
            paymentReference: 'ATFL/MAN/15373',
            orderId: 'SVC-675',
        ));
        $text = $this->pdfText($binary);

        $this->assertStringContainsString('PO Number', $text);
        $this->assertStringContainsString('ATFL/MAN/15373', $text);
        $this->assertLessThan(strpos($text, 'ATFL/MAN/15373'), strpos($text, 'SVC-675'));
    }

    public function test_product_pos_invoice_regression_unchanged(): void
    {
        $sale = $this->completePosSale(
            sku: 'MFS110-PAGINATION',
            serials: ['SN-POS-PAGINATION-1'],
            paymentReference: 'UPI-PAGINATION-REF',
        );
        $invoice = StatutoryInvoice::query()->where('inventory_sale_id', $sale->id)->firstOrFail();
        $binary = $this->invoicePdf($invoice);
        $text = $this->pdfText($binary);

        $this->assertSame(StatutoryInvoiceChannel::DeskPos, $invoice->channel);
        $this->assertStringContainsString('Reference No.', $text);
        $this->assertStringContainsString('UPI-PAGINATION-REF', $text);
        $this->assertStringNotContainsString('PO Number', $text);
        $this->assertMainInvoiceFooterOnFirstPage($binary);
    }

    private function inv0767220Payload(): StatutoryInvoicePdfPayload
    {
        return new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-0767220',
            issuedAt: '2026-09-22 16:00:00',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: '1312, Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            buyerName: 'Sundrop Brands Limited',
            buyerGstin: '09AAECA0303M1ZX',
            billingAddress: '101 LAL KUAN, SHIV MANDIR, G.T. ROAD',
            placeOfSupply: 'Uttar Pradesh',
            lines: [[
                'description' => 'Secondary Freight Reverse Auction',
                'hsnSac' => '998311',
                'qty' => 1,
                'unitPrice' => '50000.00',
                'taxableValue' => '50000.00',
                'gstPercentage' => '18.00%',
                'cgst' => '0.00',
                'sgst' => '0.00',
                'igst' => '9000.00',
                'taxTotal' => '9000.00',
                'lineTotal' => '59000.00',
                'uqc' => 'OTH',
            ]],
            taxableValue: '50000.00',
            gstRate: '18.00%',
            taxTotal: '9000.00',
            cgst: '0.00',
            sgst: '0.00',
            igst: '9000.00',
            invoiceValue: '59000.00',
            serialNumbers: [],
            serialGroups: [],
            channel: StatutoryInvoiceChannel::DeskService->value,
            paymentMethod: null,
            paymentStatus: null,
            paymentReference: 'ATFL/MAN/15374',
            orderId: 'SVC-676',
        );
    }

    /**
     * @param  list<string>  $modelA
     * @param  list<string>  $modelB
     */
    private function inv0767211Payload(array $modelA, array $modelB): StatutoryInvoicePdfPayload
    {
        return $this->multiGroupPayload(
            modelA: $modelA,
            modelB: $modelB,
            invoiceNumber: 'INV-0767211',
            orderId: 'POS-6735',
            withIrn: true,
            withPayment: true,
            buyerName: 'PRASHAD COMPUTERS',
            buyerGstin: '07AAIPJ8084F1ZI',
            invoiceValue: '227032.00',
            taxableValue: '192400.00',
            taxTotal: '34632.00',
            cgst: '17316.00',
            sgst: '17316.00',
        );
    }

    /**
     * @param  list<string>  $modelA
     * @param  list<string>  $modelB
     */
    private function multiGroupPayload(
        array $modelA,
        array $modelB,
        string $invoiceNumber = 'INV-0767211-TEST',
        string $orderId = 'POS-TEST',
        bool $withIrn = false,
        bool $withPayment = false,
        string $buyerName = 'Hardware Buyer',
        ?string $buyerGstin = '07AAAAA0000A1Z5',
        string $invoiceValue = '12272.00',
        string $taxableValue = '10400.00',
        string $taxTotal = '1872.00',
        string $cgst = '936.00',
        string $sgst = '936.00',
    ): StatutoryInvoicePdfPayload {
        return $this->basePayload(
            serialNumbers: array_merge($modelA, $modelB),
            invoiceNumber: $invoiceNumber,
            orderId: $orderId,
            withIrn: $withIrn,
            withPayment: $withPayment,
            buyerName: $buyerName,
            buyerGstin: $buyerGstin,
            invoiceValue: $invoiceValue,
            taxableValue: $taxableValue,
            taxTotal: $taxTotal,
            cgst: $cgst,
            sgst: $sgst,
            serialGroups: [
                [
                    'label' => 'RBMFS110L1 — Mantra MFS 110 L1 Single Fingerprint Biometric Scanner',
                    'serials' => $modelA,
                ],
                [
                    'label' => 'RBFM220UFP — Access FM220 USB L1 Single Fingerprint Scanner',
                    'serials' => $modelB,
                ],
            ],
            lines: [
                [
                    'description' => 'RBMFS110L1 — Mantra MFS 110 L1 Single Fingerprint Biometric Scanner',
                    'hsnSac' => '84716050',
                    'qty' => count($modelA),
                    'unitPrice' => '1830.00',
                    'taxableValue' => '183000.00',
                    'gstPercentage' => '18.00%',
                    'cgst' => '16470.00',
                    'sgst' => '16470.00',
                    'igst' => '0.00',
                    'taxTotal' => '32940.00',
                    'lineTotal' => '215940.00',
                    'uqc' => 'PCS',
                ],
                [
                    'description' => 'RBFM220UFP — Access FM220 USB L1 Single Fingerprint Scanner',
                    'hsnSac' => '84716050',
                    'qty' => count($modelB),
                    'unitPrice' => '1880.00',
                    'taxableValue' => '9400.00',
                    'gstPercentage' => '18.00%',
                    'cgst' => '846.00',
                    'sgst' => '846.00',
                    'igst' => '0.00',
                    'taxTotal' => '1692.00',
                    'lineTotal' => '11092.00',
                    'uqc' => 'PCS',
                ],
            ],
        );
    }

    /**
     * @param  list<string>  $serialNumbers
     * @param  list<array{label: string, serials: list<string>}>  $serialGroups
     * @param  list<array<string, mixed>>  $lines
     */
    private function basePayload(
        array $serialNumbers = [],
        array $serialGroups = [],
        ?string $channel = null,
        ?string $paymentReference = null,
        ?string $orderId = 'POS-TEST',
        string $invoiceNumber = 'INV-PAGINATION-TEST',
        array $lines = [],
        bool $withIrn = false,
        bool $withPayment = false,
        string $buyerName = 'Hardware Buyer',
        ?string $buyerGstin = '07AAAAA0000A1Z5',
        string $invoiceValue = '1180.00',
        string $taxableValue = '1000.00',
        string $taxTotal = '180.00',
        string $cgst = '90.00',
        string $sgst = '90.00',
    ): StatutoryInvoicePdfPayload {
        if ($lines === []) {
            $lines = [[
                'description' => 'RBMFS110L1 — Mantra MFS 110 L1',
                'hsnSac' => '84716050',
                'qty' => max(count($serialNumbers), 1),
                'unitPrice' => '100.00',
                'taxableValue' => '1000.00',
                'gstPercentage' => '18.00%',
                'cgst' => '90.00',
                'sgst' => '90.00',
                'igst' => '0.00',
                'taxTotal' => '180.00',
                'lineTotal' => '1180.00',
                'uqc' => 'PCS',
            ]];
        }

        return new StatutoryInvoicePdfPayload(
            invoiceNumber: $invoiceNumber,
            issuedAt: '2026-09-22 14:48:00',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: '1312, Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            buyerName: $buyerName,
            buyerGstin: $buyerGstin,
            billingAddress: '1 Test Street, Delhi',
            placeOfSupply: 'Delhi',
            lines: $lines,
            taxableValue: $taxableValue,
            gstRate: '18.00%',
            taxTotal: $taxTotal,
            cgst: $cgst,
            sgst: $sgst,
            igst: '0.00',
            invoiceValue: $invoiceValue,
            serialNumbers: $serialNumbers,
            serialGroups: $serialGroups,
            channel: $channel,
            paymentMethod: $withPayment ? 'Bank Transfer' : ($paymentReference !== null ? 'UPI' : null),
            paymentStatus: $withPayment || $paymentReference !== null ? 'Paid' : null,
            paymentReference: $paymentReference,
            orderId: $orderId,
            irn: $withIrn ? self::IRN : null,
            ackNo: $withIrn ? '172621230160465' : null,
            ackDate: $withIrn ? '2026-09-22 14:48:00' : null,
            signedQr: $withIrn ? 'eyJhbGciOiJFUzI1NiJ9.payload.signature' : null,
        );
    }

    /**
     * @return list<string>
     */
    private function serialList(string $prefix, int $count): array
    {
        $serials = [];
        for ($i = 1; $i <= $count; $i++) {
            $serials[] = sprintf('%s-%03d', $prefix, $i);
        }

        return $serials;
    }

    /**
     * @return list<string>
     */
    private function inv0767211ModelASerials(): array
    {
        $serials = [];
        for ($i = 1; $i <= 100; $i++) {
            $serials[] = sprintf('109%05d', 15000 + $i);
        }

        return $serials;
    }

    /**
     * @return list<string>
     */
    private function inv0767211ModelBSerials(): array
    {
        return [
            'M260758058',
            'M260758070',
            'M260758326',
            'M260758531',
            'M260758589',
        ];
    }

    /**
     * @param  list<string>  $serials
     */
    private function completePosSale(string $sku, array $serials, string $paymentReference): \App\Models\InventorySale
    {
        $product = InventoryProduct::query()->create([
            'sku' => $sku,
            'name' => 'Mantra MFS110',
            'hsn_code' => '84716050',
            'uqc' => 'PCS',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($product, $this->branch, $serials, $this->actor);

        return app(PosSaleService::class)->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Walk-in Buyer', 'phone' => '9000002500'],
            lines: [[
                'product_id' => $product->id,
                'qty' => count($serials),
                'serials' => $serials,
            ]],
            paymentMethod: 'UPI',
            paymentReference: $paymentReference,
            actor: $this->actor,
            statutory: [
                'place_of_supply_state' => 'Delhi',
                'billing_address' => '1 Test Street, Delhi',
                'billing_city' => 'New Delhi',
                'billing_state' => 'Delhi',
                'billing_pincode' => '110001',
            ],
        );
    }

    private function invoicePdf(StatutoryInvoice $invoice): string
    {
        $document = app(\App\Services\StatutoryInvoice\StatutoryDocumentService::class)
            ->regeneratePresentation($invoice, $this->actor);

        return Storage::disk('local')->get($document->path);
    }
}
