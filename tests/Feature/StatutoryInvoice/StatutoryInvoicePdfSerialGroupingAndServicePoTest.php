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
        $serials = $this->serialList('SN-B', 15);
        $binary = (new SimplePdfRenderer)->render($this->singleGroupPayload($serials));

        $this->assertOptionBWithAnnexure($binary, $serials);
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

        $this->assertStringContainsString('RBMFS110L1', $text);
        $this->assertStringContainsString('RBFM220UFP', $text);
        $this->assertStringNotContainsString('ANNEXURE A', $text);
        foreach (array_merge($modelASerials, $modelBSerials) as $serial) {
            $this->assertSerialPresentInPdf($binary, $serial);
        }

        $modelAIndex = strpos($text, 'RBMFS110L1');
        $modelBIndex = strpos($text, 'RBFM220UFP');
        $this->assertNotFalse($modelAIndex);
        $this->assertNotFalse($modelBIndex);
        $this->assertLessThan($modelBIndex, $modelAIndex);
        $this->assertLessThan(strpos($text, $modelBSerials[0]), strpos($text, $modelASerials[0]));
    }

    public function test_multi_model_invoice_with_one_group_over_ten_and_one_group_under_ten(): void
    {
        $modelASerials = $this->serialList('MFS110', 12);
        $modelBSerials = $this->serialList('FM220', 3);
        $binary = (new SimplePdfRenderer)->render($this->multiGroupPayload(
            modelA: $modelASerials,
            modelB: $modelBSerials,
        ));
        $text = $this->pdfText($binary);

        $this->assertStringContainsString('ANNEXURE A', $text);
        $this->assertStringContainsString('Complete serial-number list provided in Annexure A.', $text);
        $this->assertStringContainsString('RBMFS110L1', $text);
        $this->assertStringContainsString('RBFM220UFP', $text);
        foreach ($modelASerials as $serial) {
            $this->assertSerialPresentInPdf($binary, $serial);
        }
        foreach ($modelBSerials as $serial) {
            $this->assertSerialPresentInPdf($binary, $serial);
        }
        $this->assertStringContainsString($modelASerials[11], $text);
        $this->assertStringNotContainsString('11.', $this->pdfLiteralSliceBefore($binary, $modelBSerials[0]));
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
        $modelASerials = $this->serialList('LIVE-A', 11);
        $modelBSerials = $this->serialList('LIVE-B', 2);
        $sale = $this->completeMultiLinePosSale($modelASerials, $modelBSerials);
        $invoice = StatutoryInvoice::query()->where('inventory_sale_id', $sale->id)->firstOrFail();
        $binary = $this->invoicePdf($invoice);
        $text = $this->pdfText($binary);

        $this->assertStringContainsString('ANNEXURE A', $text);
        $this->assertStringContainsString('RBMFS110L1', $text);
        $this->assertStringContainsString('RBFM220UFP', $text);
        foreach (array_merge($modelASerials, $modelBSerials) as $serial) {
            $this->assertSerialPresentInPdf($binary, $serial);
        }
        $this->assertStringContainsString($modelASerials[10], $text);
    }

    /**
     * @param  list<string>  $serials
     */
    private function singleGroupPayload(array $serials): StatutoryInvoicePdfPayload
    {
        return $this->basePayload(
            serialNumbers: $serials,
            serialGroups: [[
                'label' => 'RBMFS110L1 — Mantra MFS 110 L1',
                'serials' => $serials,
            ]],
        );
    }

    /**
     * @param  list<string>  $modelA
     * @param  list<string>  $modelB
     */
    private function multiGroupPayload(array $modelA, array $modelB): StatutoryInvoicePdfPayload
    {
        return $this->basePayload(
            serialNumbers: array_merge($modelA, $modelB),
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
        array $lines = [],
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
            invoiceNumber: 'INV-0767211-TEST',
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

    private function pdfLiteralSliceBefore(string $binary, string $needle): string
    {
        $decoded = $this->pdfText($binary);
        $position = strpos($decoded, $needle);
        if ($position === false) {
            return $decoded;
        }

        return substr($decoded, 0, $position);
    }
}
