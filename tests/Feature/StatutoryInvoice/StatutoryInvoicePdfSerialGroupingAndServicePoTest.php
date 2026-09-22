<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\StatutoryInvoiceChannel;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySerial;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceDocument;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\StatutoryInvoice\Data\StatutoryInvoicePdfPayload;
use App\Services\StatutoryInvoice\SimplePdfRenderer;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AssertsStatutoryInvoicePdfSerials;
use Tests\TestCase;

class StatutoryInvoicePdfSerialGroupingAndServicePoTest extends TestCase
{
    use AssertsStatutoryInvoicePdfSerials;
    use RefreshDatabase;

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

    public function test_single_model_with_up_to_ten_serials_renders_without_annexure(): void
    {
        $serials = $this->serialList('SN-A', 8);
        $binary = (new SimplePdfRenderer)->render($this->singleGroupPayload($serials));

        $this->assertOptionBMainPageOnly($binary, $serials);
    }

    public function test_single_model_with_more_than_ten_serials_uses_option_b_annexure_regression(): void
    {
        $serials = $this->serialList('SN-B', 120);
        $binary = (new SimplePdfRenderer)->render($this->singleGroupPayload($serials, withIrn: true));

        $this->assertOptionBWithAnnexure($binary, $serials);
        $this->assertMainInvoiceFooterOnFirstPage($binary, 'issued-irn-token-0001');
    }

    public function test_multiple_models_render_serials_grouped_by_invoice_line(): void
    {
        $modelASerials = $this->serialList('MFS110', 6);
        $modelBSerials = $this->serialList('FM220', 4);
        $binary = (new SimplePdfRenderer)->render($this->multiGroupPayload(
            modelA: $modelASerials,
            modelB: $modelBSerials,
        ));
        $text = $this->pdfText($binary);

        $this->assertStringContainsString('Mantra MFS 110 L1', $text);
        $this->assertStringContainsString('Access FM220 USB L1', $text);
        $this->assertStringNotContainsString('ANNEXURE A', $text);
        foreach (array_merge($modelASerials, $modelBSerials) as $serial) {
            $this->assertSerialPresentInPdf($binary, $serial);
        }

        $modelAIndex = strpos($text, 'Mantra MFS 110 L1');
        $modelBIndex = strpos($text, 'Access FM220 USB L1');
        $this->assertNotFalse($modelAIndex);
        $this->assertNotFalse($modelBIndex);
        $this->assertLessThan($modelBIndex, $modelAIndex);
        $this->assertLessThan(strpos($text, $modelBSerials[0]), strpos($text, $modelASerials[0]));
    }

    public function test_multi_model_annexure_includes_all_groups_when_one_model_exceeds_ten(): void
    {
        $modelASerials = $this->serialList('MFS110', 100);
        $modelBSerials = $this->serialList('FM220', 5);
        $binary = (new SimplePdfRenderer)->render($this->multiGroupPayload(
            modelA: $modelASerials,
            modelB: $modelBSerials,
        ));
        $extracted = $this->extractedPdfText($binary);
        $main = $this->mainPageExtractedText($extracted);

        $this->assertGroupedAnnexureComplete($binary, [
            'Mantra MFS 110 L1' => $modelASerials,
            'Access FM220 USB L1' => $modelBSerials,
        ], 105);
        $this->assertSerialsPresentInExtractedText($main, array_slice($modelASerials, 0, 10), 'Main page Model A preview');
        $this->assertSerialsPresentInExtractedText($main, $modelBSerials, 'Main page Model B preview');
        $this->assertSerialsAbsentFromExtractedText($main, [$modelASerials[10]], 'Main page Model A annexure-only serial');
    }

    public function test_multi_model_with_both_groups_under_ten_does_not_generate_annexure(): void
    {
        $modelASerials = $this->serialList('MFS110', 5);
        $modelBSerials = $this->serialList('FM220', 5);
        $binary = (new SimplePdfRenderer)->render($this->multiGroupPayload(
            modelA: $modelASerials,
            modelB: $modelBSerials,
        ));
        $extracted = $this->extractedPdfText($binary);

        $this->assertStringNotContainsString('ANNEXURE A', $extracted);
        $this->assertSerialsPresentInExtractedText($extracted, array_merge($modelASerials, $modelBSerials), 'Main page');
    }

    public function test_multi_model_annexure_includes_small_group_when_large_group_requires_annexure(): void
    {
        $modelASerials = $this->serialList('MFS110', 100);
        $modelBSerials = $this->serialList('FM220', 5);
        $binary = (new SimplePdfRenderer)->render($this->multiGroupPayload(
            modelA: $modelASerials,
            modelB: $modelBSerials,
        ));
        $extracted = $this->extractedPdfText($binary);
        $main = $this->mainPageExtractedText($extracted);

        $this->assertGroupedAnnexureComplete($binary, [
            'Mantra MFS 110 L1' => $modelASerials,
            'Access FM220 USB L1' => $modelBSerials,
        ], 105);
        $this->assertSerialsPresentInExtractedText($main, array_slice($modelASerials, 0, 10), 'Main page Model A preview');
        $this->assertSerialsPresentInExtractedText($main, $modelBSerials, 'Main page Model B preview');
        $this->assertMainInvoiceFooterOnFirstPage($binary);
    }

    public function test_multi_model_annexure_includes_every_group_when_all_groups_exceed_ten(): void
    {
        $modelASerials = $this->serialList('MFS110', 50);
        $modelBSerials = $this->serialList('FM220', 50);
        $binary = (new SimplePdfRenderer)->render($this->multiGroupPayload(
            modelA: $modelASerials,
            modelB: $modelBSerials,
        ));

        $this->assertGroupedAnnexureComplete($binary, [
            'Mantra MFS 110 L1' => $modelASerials,
            'Access FM220 USB L1' => $modelBSerials,
        ], 100);
        $this->assertMainInvoiceFooterOnFirstPage($binary);
    }

    public function test_inv_0767211_style_fixture_lists_all_one_hundred_five_serials_once_in_annexure_groups(): void
    {
        $modelASerials = $this->inv0767211ModelASerials();
        $modelBSerials = $this->inv0767211ModelBSerials();
        $binary = (new SimplePdfRenderer)->render($this->multiGroupPayload(
            modelA: $modelASerials,
            modelB: $modelBSerials,
            invoiceNumber: 'INV-0767211',
            orderId: 'POS-6735',
        ));

        $this->assertGroupedAnnexureComplete($binary, [
            'Mantra MFS 110 L1' => $modelASerials,
            'Access FM220 USB L1' => $modelBSerials,
        ], 105);

        $annexure = $this->annexureExtractedText($this->extractedPdfText($binary));
        foreach (array_merge($modelASerials, $modelBSerials) as $serial) {
            $this->assertSame(
                1,
                substr_count($annexure, $serial),
                "Annexure must contain serial {$serial} exactly once.",
            );
        }
    }

    public function test_desk_service_po_number_renders_below_order_id_and_not_as_payment_reference(): void
    {
        $binary = (new SimplePdfRenderer)->render($this->basePayload(
            channel: StatutoryInvoiceChannel::DeskService->value,
            paymentReference: 'ATFL/MAN/15373',
            orderId: 'SVC-675',
        ));
        $text = $this->pdfText($binary);

        $this->assertStringContainsString('PO Number', $text);
        $this->assertStringContainsString('ATFL/MAN/15373', $text);
        $this->assertStringContainsString('Order ID', $text);
        $this->assertStringContainsString('SVC-675', $text);
        $this->assertLessThan(strpos($text, 'ATFL/MAN/15373'), strpos($text, 'SVC-675'));
        $this->assertStringNotContainsString('Reference No.', $text);
    }

    public function test_product_pos_keeps_reference_number_in_payment_details(): void
    {
        $sale = $this->completePosSale(
            sku: 'MFS110-REF',
            serials: ['SN-POS-REF-1'],
            paymentReference: 'UPI-REF-POS',
        );
        $invoice = StatutoryInvoice::query()->where('inventory_sale_id', $sale->id)->firstOrFail();
        $binary = $this->invoicePdf($invoice);
        $text = $this->pdfText($binary);

        $this->assertSame(StatutoryInvoiceChannel::DeskPos, $invoice->channel);
        $this->assertStringContainsString('Reference No.', $text);
        $this->assertStringContainsString('UPI-REF-POS', $text);
        $this->assertStringNotContainsString('PO Number', $text);
    }

    public function test_multi_line_pos_sale_groups_serials_by_sale_line_allocation(): void
    {
        $modelASerials = $this->serialList('LIVE-A', 100);
        $modelBSerials = $this->serialList('LIVE-B', 5);
        $sale = $this->completeMultiLinePosSale($modelASerials, $modelBSerials);
        $invoice = StatutoryInvoice::query()->where('inventory_sale_id', $sale->id)->firstOrFail();
        $binary = $this->invoicePdf($invoice);

        $this->assertGroupedAnnexureComplete($binary, [
            'Mantra MFS 110 L1' => $modelASerials,
            'Access FM220 USB L1' => $modelBSerials,
        ], 105);
        $this->assertMainInvoiceFooterOnFirstPage($binary);
    }

    /**
     * @param  list<string>  $serials
     */
    private function singleGroupPayload(array $serials, bool $withIrn = false): StatutoryInvoicePdfPayload
    {
        return $this->basePayload(
            serialNumbers: $serials,
            serialGroups: [[
                'label' => 'RBMFS110L1 — Mantra MFS 110 L1',
                'serials' => $serials,
            ]],
            withIrn: $withIrn,
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
    ): StatutoryInvoicePdfPayload {
        return $this->basePayload(
            serialNumbers: array_merge($modelA, $modelB),
            invoiceNumber: $invoiceNumber,
            orderId: $orderId,
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
                    'unitPrice' => '100.00',
                    'taxableValue' => '10000.00',
                    'gstPercentage' => '18.00%',
                    'cgst' => '900.00',
                    'sgst' => '900.00',
                    'igst' => '0.00',
                    'taxTotal' => '1800.00',
                    'lineTotal' => '11800.00',
                    'uqc' => 'PCS',
                ],
                [
                    'description' => 'RBFM220UFP — Access FM220 USB L1 Single Fingerprint Scanner',
                    'hsnSac' => '84716050',
                    'qty' => count($modelB),
                    'unitPrice' => '200.00',
                    'taxableValue' => '400.00',
                    'gstPercentage' => '18.00%',
                    'cgst' => '36.00',
                    'sgst' => '36.00',
                    'igst' => '0.00',
                    'taxTotal' => '72.00',
                    'lineTotal' => '472.00',
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
        string $invoiceNumber = 'INV-0767211-TEST',
        array $lines = [],
        bool $withIrn = false,
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
            issuedAt: '2026-09-22 12:00:00',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: '1312, Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            buyerName: 'Hardware Buyer',
            buyerGstin: '07AAAAA0000A1Z5',
            billingAddress: '1 Test Street, Delhi',
            placeOfSupply: 'Delhi',
            lines: $lines,
            taxableValue: '1000.00',
            gstRate: '18.00%',
            taxTotal: '180.00',
            cgst: '90.00',
            sgst: '90.00',
            igst: '0.00',
            invoiceValue: '1180.00',
            serialNumbers: $serialNumbers,
            serialGroups: $serialGroups,
            channel: $channel,
            paymentMethod: $paymentReference !== null ? 'UPI' : null,
            paymentStatus: $paymentReference !== null ? 'Paid' : null,
            paymentReference: $paymentReference,
            orderId: $orderId,
            irn: $withIrn ? 'issued-irn-token-0001' : null,
            ackNo: $withIrn ? '112233' : null,
            ackDate: $withIrn ? '2026-09-22 12:00:00' : null,
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
            customer: ['name' => 'Walk-in Buyer', 'phone' => '9000002400'],
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

    /**
     * @param  list<string>  $modelASerials
     * @param  list<string>  $modelBSerials
     */
    private function completeMultiLinePosSale(array $modelASerials, array $modelBSerials): \App\Models\InventorySale
    {
        $productA = InventoryProduct::query()->create([
            'sku' => 'RBMFS110L1',
            'name' => 'Mantra MFS 110 L1 Single Fingerprint Biometric Scanner',
            'hsn_code' => '84716050',
            'uqc' => 'PCS',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $productB = InventoryProduct::query()->create([
            'sku' => 'RBFM220UFP',
            'name' => 'Access FM220 USB L1 Single Fingerprint Scanner',
            'hsn_code' => '84716050',
            'uqc' => 'PCS',
            'gst_percentage' => 18,
            'unit_price' => 200,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($productA, $this->branch, $modelASerials, $this->actor);
        app(InventoryStockService::class)->stockInSerialized($productB, $this->branch, $modelBSerials, $this->actor);

        $sale = app(PosSaleService::class)->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Walk-in Buyer', 'phone' => '9000002401'],
            lines: [
                [
                    'product_id' => $productA->id,
                    'qty' => count($modelASerials),
                    'serials' => $modelASerials,
                ],
                [
                    'product_id' => $productB->id,
                    'qty' => count($modelBSerials),
                    'serials' => $modelBSerials,
                ],
            ],
            paymentMethod: 'Cash',
            paymentReference: 'CASH-MULTI-MODEL',
            actor: $this->actor,
            statutory: [
                'place_of_supply_state' => 'Delhi',
                'billing_address' => '1 Test Street, Delhi',
                'billing_city' => 'New Delhi',
                'billing_state' => 'Delhi',
                'billing_pincode' => '110001',
                'buyer_gstin' => '07AAAAA0000A1Z5',
            ],
        );

        $this->assertSame(
            count($modelASerials) + count($modelBSerials),
            InventorySerial::query()->whereIn('serial_number', array_merge($modelASerials, $modelBSerials))->count(),
        );

        return $sale;
    }

    private function invoicePdf(StatutoryInvoice $invoice): string
    {
        $document = StatutoryInvoiceDocument::query()->where('invoice_id', $invoice->id)->firstOrFail();

        return app(StatutoryDocumentService::class)->binary($document);
    }

    private function pdfText(string $pdf): string
    {
        return str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $pdf);
    }

    /**
     * @return list<string>
     */
    private function inv0767211ModelASerials(): array
    {
        return [
            '10915091', '10919561', '10928298', '10934742', '10960303', '10998661', '10998882', '11000884',
            '11001165', '11001181', '11001272', '11001352', '11001530', '11001560', '11001566', '11001571',
            '11001579', '11001707', '11001722', '11002115', '11002119', '11002127', '11002467', '11002799',
            '11002835', '11002873', '11002881', '11002882', '11002891', '11002913', '11002920', '11002928',
            '11002936', '11002978', '11002982', '11002989', '11003005', '11003022', '11003035', '11003072',
            '11003129', '11003154', '11004523', '11004555', '11004562', '11004669', '11004677', '11008760',
            '11009237', '11009610', '11111311', '11111319', '11111350', '11111354', '11111432', '11111435',
            '11111439', '11111448', '11111458', '11113057', '11113157', '11113220', '11119552', '11120140',
            '11120270', '11120280', '11120340', '11120342', '11120376', '11120383', '11120413', '11120427',
            '11120441', '11120474', '11120503', '11120513', '11120519', '11120532', '11120556', '11120578',
            '11120629', '11120637', '11121017', '11121032', '11121055', '11121093', '11121098', '11121100',
            '11121251', '11121785', '11121979', '11122009', '11122022', '11122036', '11122047', '11122066',
            '11122082', '11122086', '11122088', '11122108',
        ];
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
}
