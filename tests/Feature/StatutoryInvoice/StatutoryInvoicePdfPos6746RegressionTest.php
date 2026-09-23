<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\EInvoiceRecord;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceDocument;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\StatutoryInvoice\Data\StatutoryInvoicePdfPayload;
use App\Services\StatutoryInvoice\SimplePdfRenderer;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use App\Services\StatutoryInvoice\StatutoryInvoicePdfPresentationException;
use App\Services\StatutoryInvoice\StatutoryInvoicePdfPresentationValidator;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Support\AssertsStatutoryInvoicePdfSerials;
use Tests\TestCase;

class StatutoryInvoicePdfPos6746RegressionTest extends TestCase
{
    use AssertsStatutoryInvoicePdfSerials;
    use RefreshDatabase;

    private const IRN = '0e333d987ab2e08d75b7ebffdd6ed219c735b6f1e776f414282fa3460302d8b4';

    private const ACK = '172621241989060';

    private const SIGNED_QR = 'eyJhbGciOiJFUzI1NiIsInR5cCI6IkpXVCJ9.eyJkYXRhIjoicG9zNjc0Ni1yZWdyZXNzaW9uIn0.dGVzdC1zaWduYXR1cmU';

    private User $actor;

    private InventoryBranch $branch;

    /** @var list<string> */
    private const MIS_TAIL_SERIALS = [
        '2667767',
        '2671345',
        '2672074',
        '2673822',
        '2685443',
        '2685521',
        '2690610',
        '2690640',
        '2743255',
        '7503439',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-23 18:35:54');
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
        Mockery::close();
        parent::tearDown();
    }

    public function test_pos_6746_shaped_invoice_renders_closing_block_before_annexure(): void
    {
        $binary = (new SimplePdfRenderer)->render($this->pos6746Payload(withIrn: true, withPayment: true));
        $invoiceSection = $this->mainPageExtractedText($this->extractedPdfText($binary));

        $this->assertInvoiceClosingPresentBeforeAnnexure($binary, self::IRN, self::ACK);
        $this->assertStringContainsString('Rs.834100.00', $invoiceSection);
        $this->assertStringContainsString('IGST', $invoiceSection);
        $this->assertStringContainsString('Rs.984238.00', $invoiceSection);
        $this->assertStringContainsString('Payment Details', $invoiceSection);
        $this->assertStringContainsString('Bank Transfer', $invoiceSection);
        $this->assertStringContainsString('Uttar Pradesh', $invoiceSection);
    }

    public function test_pos_6746_shaped_invoice_does_not_end_invoice_section_without_closing_when_preview_consumes_space(): void
    {
        $binary = (new SimplePdfRenderer)->render($this->pos6746Payload(withIrn: true));
        $extracted = $this->extractedPdfText($binary);
        $annexurePos = strpos($extracted, 'ANNEXURE A');
        $this->assertNotFalse($annexurePos);
        $invoiceSection = substr($extracted, 0, $annexurePos);

        $this->assertStringContainsString('Morpho MSO 1300 E3 RD L1', $invoiceSection);
        $this->assertStringContainsString('TOTAL INVOICE VALUE', $invoiceSection);
        $this->assertStringContainsString(SimplePdfRenderer::ANNEXURE_NOTICE_TEXT, $invoiceSection);
        $this->assertPage1HasZeroInlineSerials($binary, array_merge(
            $this->pos6746SerialGroups()['morpho'],
            $this->pos6746SerialGroups()['gps'],
            $this->pos6746SerialGroups()['mis'],
            $this->pos6746SerialGroups()['mfs'],
        ));
        $this->assertAnnexureStartsOnPageTwo($binary);
        $this->assertA4PageDimensions($binary);
    }

    public function test_pos_6746_shaped_annexure_includes_all_four_hundred_thirty_serials(): void
    {
        $groups = $this->pos6746SerialGroups();
        $binary = (new SimplePdfRenderer)->render($this->pos6746Payload(withIrn: true));

        $extracted = $this->extractedPdfText($binary);
        $this->assertStringContainsString('ANNEXURE A', $extracted);
        $this->assertStringContainsString('Total serials', $extracted);
        $this->assertMatchesRegularExpression('/\n430\n/', $extracted);

        $this->assertAllSerialsPresentInPdfBinary($binary, array_merge(
            $groups['morpho'],
            $groups['gps'],
            $groups['mis'],
            $groups['mfs'],
        ));
        $this->assertAllSerialsPresentInPdfBinary($binary, self::MIS_TAIL_SERIALS);
    }

    public function test_pos_6746_shaped_group_serial_counts_match_snapshot_quantities(): void
    {
        $groups = $this->pos6746SerialGroups();
        $binary = (new SimplePdfRenderer)->render($this->pos6746Payload());

        $this->assertCount(70, $groups['morpho']);
        $this->assertCount(10, $groups['gps']);
        $this->assertCount(150, $groups['mis']);
        $this->assertCount(200, $groups['mfs']);

        foreach ($groups as $label => $serials) {
            foreach ($serials as $serial) {
                $this->assertSerialPresentInPdf($binary, $serial, "{$label} serial {$serial} must appear in PDF.");
            }
        }
    }

    public function test_inv_0767292_regression_contract_locks_verified_production_shape(): void
    {
        $groups = $this->pos6746SerialGroups();
        $allSerials = array_merge($groups['morpho'], $groups['gps'], $groups['mis'], $groups['mfs']);
        $binary = (new SimplePdfRenderer)->render($this->pos6746Payload(withIrn: true, withPayment: true));

        $this->assertStatutoryPdfLayoutConstantsLocked();
        $this->assertA4PageDimensions($binary);
        $this->assertPdfPageCount($binary, 3);
        $this->assertPage1StructureForAnnexureInvoice($binary, self::IRN, self::ACK);
        $this->assertPage1HasZeroInlineSerials($binary, $allSerials);
        $this->assertGroupedAnnexureComplete($binary, [
            'Morpho MSO 1300 E3 RD L1' => $groups['morpho'],
            'UGR86' => $groups['gps'],
            'MIS100' => $groups['mis'],
            'MFS 110' => $groups['mfs'],
        ], 430);
        $this->assertAnnexureContainsCompleteSerialPopulation($binary, $allSerials);
        $this->assertNoDuplicateSerialsInPdf($binary, $allSerials);
        $this->assertNoOrphanedBlankPages($binary);
        $this->assertStringContainsString('INV-0767292', $this->firstMainInvoicePageText($this->extractedPdfText($binary)));
        $this->assertStringContainsString('POS-6746', $this->extractedPdfText($binary));
        $this->assertStringContainsString('Nine Lakh Eighty-Four Thousand Two Hundred Thirty-Eight Rupees Only', $this->extractedPdfText($binary));
    }

    public function test_regenerate_presentation_preserves_invoice_and_e_invoice_identity_for_pos_6746_shape(): void
    {
        $invoice = $this->mintB2bPosInvoice(['SN-P6746-IMMUTABLE']);
        $this->attachSubmittedIrn($invoice);

        $fresh = $invoice->fresh(['items', 'eInvoiceRecord']);
        $beforeInvoice = [
            'invoice_number' => $fresh->invoice_number,
            'status' => $fresh->status->value,
            'taxable_value' => (string) $fresh->taxable_value,
            'tax_total' => (string) $fresh->tax_total,
            'invoice_value' => (string) $fresh->invoice_value,
            'cgst' => (string) $fresh->cgst,
            'sgst' => (string) $fresh->sgst,
            'igst' => (string) $fresh->igst,
        ];
        $beforeRecord = $invoice->eInvoiceRecord?->only(['irn', 'ack_no', 'status']);
        $invoiceCount = StatutoryInvoice::query()->count();

        app(StatutoryDocumentService::class)->regeneratePresentation($invoice->fresh(['items', 'eInvoiceRecord']));

        $afterInvoice = $invoice->fresh(['items', 'eInvoiceRecord', 'document']);
        $afterRecord = $afterInvoice->eInvoiceRecord?->only(['irn', 'ack_no', 'status']);
        $afterSnapshot = [
            'invoice_number' => $afterInvoice->invoice_number,
            'status' => $afterInvoice->status->value,
            'taxable_value' => (string) $afterInvoice->taxable_value,
            'tax_total' => (string) $afterInvoice->tax_total,
            'invoice_value' => (string) $afterInvoice->invoice_value,
            'cgst' => (string) $afterInvoice->cgst,
            'sgst' => (string) $afterInvoice->sgst,
            'igst' => (string) $afterInvoice->igst,
        ];

        $this->assertSame($beforeInvoice, $afterSnapshot);
        $this->assertSame($beforeRecord, $afterRecord);
        $this->assertSame($invoiceCount, StatutoryInvoice::query()->count());
        $this->assertStringContainsString(
            self::IRN,
            $this->extractedPdfText(app(StatutoryDocumentService::class)->binary($afterInvoice->document)),
        );
    }

    public function test_finalize_after_irn_validates_pdf_contains_irn_ack_and_qr(): void
    {
        $invoice = $this->mintB2bPosInvoice(['SN-P6746-FINALIZE']);
        $this->attachSubmittedIrn($invoice);

        $document = app(StatutoryDocumentService::class)->finalizeAfterIrn($invoice->fresh(['items', 'eInvoiceRecord']));
        $binary = app(StatutoryDocumentService::class)->binary($document);
        $text = $this->extractedPdfText($binary);

        $this->assertStringContainsString(self::IRN, $text);
        $this->assertStringContainsString('Ack No: '.self::ACK, $text);
        $this->assertStringContainsString('% signed-qr-image', $binary);
        $this->assertStringContainsString('e-Invoice Verification', $text);
        $this->assertStringContainsString('TOTAL INVOICE VALUE', $text);
    }

    public function test_finalize_marks_document_failed_when_pdf_validation_fails_without_touching_irn(): void
    {
        $invoice = $this->mintB2bPosInvoice(['SN-P6746-VALIDATE']);
        $this->attachSubmittedIrn($invoice);

        $validator = Mockery::mock(StatutoryInvoicePdfPresentationValidator::class);
        $validator->shouldReceive('validate')
            ->once()
            ->andThrow(new StatutoryInvoicePdfPresentationException('Generated statutory PDF does not contain the issued IRN.'));
        $this->app->instance(StatutoryInvoicePdfPresentationValidator::class, $validator);

        $this->expectException(StatutoryInvoicePdfPresentationException::class);

        try {
            app(StatutoryDocumentService::class)->finalizeAfterIrn($invoice->fresh(['items', 'eInvoiceRecord']));
        } finally {
            $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->firstOrFail();
            $document = StatutoryInvoiceDocument::query()->where('invoice_id', $invoice->id)->first();
            $this->assertSame(EInvoiceRecordStatus::Submitted->value, $record->status);
            $this->assertSame(self::IRN, $record->irn);
            $this->assertSame(1, StatutoryInvoice::query()->whereKey($invoice->id)->count());
            $this->assertNotNull($document);
            $this->assertSame('failed', $document->status->value);
        }
    }

    public function test_inv_0767211_fixture_remains_intact_after_renderer_fixes(): void
    {
        $modelA = $this->serialList('109', 100, 15000);
        $modelB = ['M260758058', 'M260758070', 'M260758326', 'M260758531', 'M260758589'];
        $binary = (new SimplePdfRenderer)->render($this->inv0767211Payload($modelA, $modelB));

        $this->assertMainInvoiceFooterOnFirstPage($binary, '127af3ac3ce92b18f7df76e2722cf935d24cfe90322cffa501261794a17f4ac2');
        $this->assertGroupedAnnexureComplete($binary, [
            'Mantra MFS 110 L1' => $modelA,
            'Access FM220 USB L1' => $modelB,
        ], 105);
    }

    public function test_large_group_continuation_preserves_serials_across_annexure_pages(): void
    {
        $serials = $this->serialList('CAP-CONT', 260);
        $binary = (new SimplePdfRenderer)->render(new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-CAP-CONT',
            issuedAt: '2026-09-23 18:35:54',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: '1312, Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            buyerName: 'VARTC',
            buyerGstin: '09AAJFV1437D1Z7',
            billingAddress: 'Azamgarh, Uttar Pradesh',
            placeOfSupply: 'Uttar Pradesh',
            lines: [[
                'description' => 'RBMFS110L1 — Mantra MFS 110 L1',
                'hsnSac' => '84716050',
                'qty' => 260,
                'unitPrice' => '1830.00',
                'taxableValue' => '475800.00',
                'gstPercentage' => '18.00%',
                'cgst' => '0.00',
                'sgst' => '0.00',
                'igst' => '85644.00',
                'taxTotal' => '85644.00',
                'lineTotal' => '561444.00',
                'uqc' => 'PCS',
            ]],
            taxableValue: '475800.00',
            gstRate: '18.00%',
            taxTotal: '85644.00',
            cgst: '0.00',
            sgst: '0.00',
            igst: '85644.00',
            invoiceValue: '561444.00',
            serialNumbers: $serials,
            serialGroups: [[
                'label' => 'RBMFS110L1 — Mantra MFS 110 L1 Single Fingerprint Biometric Scanner',
                'serials' => $serials,
            ]],
            channel: StatutoryInvoiceChannel::DeskPos->value,
            orderId: 'POS-CAP-CONT',
        ));

        $this->assertGreaterThan(1, $this->annexurePageCount($binary));
        $this->assertStringContainsString('ANNEXURE A (continued)', $this->extractedPdfText($binary));
        $this->assertAllSerialsPresentInPdfBinary($binary, $serials);
        $this->assertInvoiceClosingPresentBeforeAnnexure($binary);
    }

    /**
     * @return array{morpho: list<string>, gps: list<string>, mis: list<string>, mfs: list<string>}
     */
    private function pos6746SerialGroups(): array
    {
        $morpho = [];
        for ($i = 1; $i <= 70; $i++) {
            $morpho[] = sprintf('2631I%06d', 1600 + $i);
        }

        $gps = [
            'H20033-MP826AH19025507-02/26',
            'H20177-MP826AH19025514-02/26',
            'H20216-MP826AK2B031569-02/26',
            'H20248-MP826AH19024745-02/26',
            'H20328-MP826AN12049620-02/26',
            'H20334-MP826AN12049648-02/26',
            'H20353-MP826AN12049454-02/26',
            'H20386-MP826AM0Y048336-02/26',
            'H20428-MP826AN12049583-02/26',
            'H20467-MP826AH19024511-02/26',
        ];

        $mis = [];
        for ($i = 1; $i <= 140; $i++) {
            $mis[] = sprintf('109%05d', 50800 + $i);
        }
        $mis = array_merge($mis, self::MIS_TAIL_SERIALS);

        $mfs = [];
        for ($i = 1; $i <= 200; $i++) {
            $mfs[] = sprintf('105%05d', 51600 + $i);
        }

        return compact('morpho', 'gps', 'mis', 'mfs');
    }

    private function pos6746Payload(bool $withIrn = false, bool $withPayment = false): StatutoryInvoicePdfPayload
    {
        $groups = $this->pos6746SerialGroups();
        $allSerials = array_merge($groups['morpho'], $groups['gps'], $groups['mis'], $groups['mfs']);

        return new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-0767292',
            issuedAt: '2026-09-23 18:35:54',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: '1312, Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            buyerName: 'VARTC',
            buyerGstin: '09AAJFV1437D1Z7',
            billingAddress: 'C/o Ganesh Prasad, asifganj, Azamgarh, Uttar Pradesh, 276001',
            placeOfSupply: 'Uttar Pradesh',
            lines: [
                [
                    'description' => 'RBIMSOE3L1 — Morpho MSO 1300 E3 RD L1 Single Fingerprint Biometric Device - Idemia with RD Service',
                    'hsnSac' => '84716050',
                    'qty' => 70,
                    'unitPrice' => '2100.00',
                    'taxableValue' => '147000.00',
                    'gstPercentage' => '18.00%',
                    'cgst' => '0.00',
                    'sgst' => '0.00',
                    'igst' => '26460.00',
                    'taxTotal' => '26460.00',
                    'lineTotal' => '173460.00',
                    'uqc' => 'PCS',
                ],
                [
                    'description' => 'RBUGR89GPS — RADIUM UGR86 89-NaviC UIDAI Approved GPS for AADHAAR',
                    'hsnSac' => '85269190',
                    'qty' => 10,
                    'unitPrice' => '1210.00',
                    'taxableValue' => '12100.00',
                    'gstPercentage' => '18.00%',
                    'cgst' => '0.00',
                    'sgst' => '0.00',
                    'igst' => '2178.00',
                    'taxTotal' => '2178.00',
                    'lineTotal' => '14278.00',
                    'uqc' => 'PCS',
                ],
                [
                    'description' => 'RBMIS100IR — Mantra MIS100 V2 Single Iris Scanner Biometric Device',
                    'hsnSac' => '84716050',
                    'qty' => 150,
                    'unitPrice' => '2060.00',
                    'taxableValue' => '309000.00',
                    'gstPercentage' => '18.00%',
                    'cgst' => '0.00',
                    'sgst' => '0.00',
                    'igst' => '55620.00',
                    'taxTotal' => '55620.00',
                    'lineTotal' => '364620.00',
                    'uqc' => 'PCS',
                ],
                [
                    'description' => 'RBMFS110L1 — Mantra MFS 110 L1 Single Fingerprint Biometric Scanner',
                    'hsnSac' => '84716050',
                    'qty' => 200,
                    'unitPrice' => '1830.00',
                    'taxableValue' => '366000.00',
                    'gstPercentage' => '18.00%',
                    'cgst' => '0.00',
                    'sgst' => '0.00',
                    'igst' => '65880.00',
                    'taxTotal' => '65880.00',
                    'lineTotal' => '431880.00',
                    'uqc' => 'PCS',
                ],
            ],
            taxableValue: '834100.00',
            gstRate: '18.00%',
            taxTotal: '150138.00',
            cgst: '0.00',
            sgst: '0.00',
            igst: '150138.00',
            invoiceValue: '984238.00',
            serialNumbers: $allSerials,
            serialGroups: [
                ['label' => 'RBIMSOE3L1 — Morpho MSO 1300 E3 RD L1 Single Fingerprint Biometric Device - Idemia with RD Service', 'serials' => $groups['morpho']],
                ['label' => 'RBUGR89GPS — RADIUM UGR86 89-NaviC UIDAI Approved GPS for AADHAAR', 'serials' => $groups['gps']],
                ['label' => 'RBMIS100IR — Mantra MIS100 V2 Single Iris Scanner Biometric Device', 'serials' => $groups['mis']],
                ['label' => 'RBMFS110L1 — Mantra MFS 110 L1 Single Fingerprint Biometric Scanner', 'serials' => $groups['mfs']],
            ],
            channel: StatutoryInvoiceChannel::DeskPos->value,
            paymentMethod: $withPayment ? 'Bank Transfer' : null,
            paymentStatus: $withPayment ? 'Paid' : null,
            orderId: 'POS-6746',
            irn: $withIrn ? self::IRN : null,
            ackNo: $withIrn ? self::ACK : null,
            ackDate: $withIrn ? '2026-09-23 18:36:00' : null,
            signedQr: $withIrn ? self::SIGNED_QR : null,
        );
    }

    /**
     * @param  list<string>  $serials
     */
    private function mintB2bPosInvoice(array $serials): StatutoryInvoice
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'RBMFS110L1',
            'name' => 'Mantra MFS 110 L1',
            'hsn_code' => '84716050',
            'uqc' => 'PCS',
            'gst_percentage' => 18,
            'unit_price' => 1830,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($product, $this->branch, $serials, $this->actor);

        $sale = app(PosSaleService::class)->completeSale(
            branch: $this->branch,
            customer: ['name' => 'VARTC', 'phone' => '9044110220'],
            lines: [[
                'product_id' => $product->id,
                'qty' => count($serials),
                'serials' => $serials,
            ]],
            paymentMethod: 'Bank Transfer',
            paymentReference: null,
            actor: $this->actor,
            statutory: [
                'buyer_gstin' => '09AAJFV1437D1Z7',
                'place_of_supply_state' => 'Uttar Pradesh',
                'billing_address' => 'C/o Ganesh Prasad, asifganj, Azamgarh, Uttar Pradesh, 276001',
                'billing_city' => 'Azamgarh',
                'billing_state' => 'Uttar Pradesh',
                'billing_pincode' => '276001',
            ],
        );

        return StatutoryInvoice::query()
            ->where('inventory_sale_id', $sale->id)
            ->firstOrFail()
            ->fresh(['items']);
    }

    private function attachSubmittedIrn(StatutoryInvoice $invoice): void
    {
        EInvoiceRecord::query()->updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'provider' => 'whitebooks',
                'status' => EInvoiceRecordStatus::Submitted->value,
                'irn' => self::IRN,
                'ack_no' => self::ACK,
                'ack_date' => '2026-09-23 18:36:00',
                'signed_qr' => self::SIGNED_QR,
                'response_payload' => ['ok' => true],
            ],
        );
    }

    /**
     * @param  list<string>  $modelA
     * @param  list<string>  $modelB
     */
    private function inv0767211Payload(array $modelA, array $modelB): StatutoryInvoicePdfPayload
    {
        return new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-0767211',
            issuedAt: '2026-09-22 14:48:00',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: '1312, Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            buyerName: 'PRASHAD COMPUTERS',
            buyerGstin: '07AAIPJ8084F1ZI',
            billingAddress: 'Delhi',
            placeOfSupply: 'Delhi',
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
            taxableValue: '192400.00',
            gstRate: '18.00%',
            taxTotal: '34632.00',
            cgst: '17316.00',
            sgst: '17316.00',
            igst: '0.00',
            invoiceValue: '227032.00',
            serialNumbers: array_merge($modelA, $modelB),
            serialGroups: [
                ['label' => 'RBMFS110L1 — Mantra MFS 110 L1 Single Fingerprint Biometric Scanner', 'serials' => $modelA],
                ['label' => 'RBFM220UFP — Access FM220 USB L1 Single Fingerprint Scanner', 'serials' => $modelB],
            ],
            channel: StatutoryInvoiceChannel::DeskPos->value,
            paymentMethod: 'Bank Transfer',
            paymentStatus: 'Paid',
            orderId: 'POS-6735',
            irn: '127af3ac3ce92b18f7df76e2722cf935d24cfe90322cffa501261794a17f4ac2',
            ackNo: '172621230160465',
            ackDate: '2026-09-22 14:48:00',
            signedQr: self::SIGNED_QR,
        );
    }

    /**
     * @return list<string>
     */
    private function serialList(string $prefix, int $count, int $start = 1): array
    {
        $serials = [];
        for ($i = 0; $i < $count; $i++) {
            $serials[] = $prefix.sprintf('%05d', $start + $i);
        }

        return $serials;
    }
}
