<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\StatutoryInvoiceChannel;
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
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AssertsStatutoryInvoicePdfSerials;
use Tests\TestCase;

class StatutoryInvoicePdfCustomerFacingSkuTest extends TestCase
{
    use AssertsStatutoryInvoicePdfSerials;
    use RefreshDatabase;

    private User $actor;

    private InventoryBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-22 16:30:00');
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

    public function test_product_invoice_pdf_omits_sku_prefix_from_line_description(): void
    {
        $payload = $this->hardwarePayload(
            lines: [[
                'description' => 'RBMFS110L1 — Mantra MFS 110 L1 Single Fingerprint Biometric Scanner',
                'hsnSac' => '84716050',
                'qty' => 1,
                'unitPrice' => '1830.00',
                'taxableValue' => '1830.00',
                'gstPercentage' => '18.00%',
                'cgst' => '164.70',
                'sgst' => '164.70',
                'igst' => '0.00',
                'taxTotal' => '329.40',
                'lineTotal' => '2159.40',
                'uqc' => 'PCS',
            ]],
        );
        $binary = (new SimplePdfRenderer)->render($payload);
        $text = $this->extractedPdfText($binary);

        $this->assertStringContainsString('Mantra MFS 110 L1', $text);
        $this->assertStringContainsString('Biometric Scanner', $text);
        $this->assertStringNotContainsString('RBMFS110L1', $text);
        $this->assertSame(
            'RBMFS110L1 — Mantra MFS 110 L1 Single Fingerprint Biometric Scanner',
            $payload->lines[0]['description'],
        );
    }

    public function test_multi_product_invoice_pdf_omits_each_sku_prefix(): void
    {
        $payload = $this->hardwarePayload(
            lines: [
                [
                    'description' => 'RBMFS110L1 — Mantra MFS 110 L1 Single Fingerprint Biometric Scanner',
                    'hsnSac' => '84716050',
                    'qty' => 100,
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
                    'qty' => 5,
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
            serialGroups: [
                [
                    'label' => 'RBMFS110L1 — Mantra MFS 110 L1 Single Fingerprint Biometric Scanner',
                    'serials' => ['SN-A-001'],
                ],
                [
                    'label' => 'RBFM220UFP — Access FM220 USB L1 Single Fingerprint Scanner',
                    'serials' => ['M260758058'],
                ],
            ],
        );
        $binary = (new SimplePdfRenderer)->render($payload);
        $text = $this->extractedPdfText($binary);

        $this->assertStringContainsString('Mantra MFS 110 L1', $text);
        $this->assertStringContainsString('Access FM220 USB L1', $text);
        $this->assertStringNotContainsString('RBMFS110L1', $text);
        $this->assertStringNotContainsString('RBFM220UFP', $text);
        $this->assertStringContainsString('84716050', $text);
        $this->assertStringContainsString('Rs.227032.00', $text);
        $this->assertStringContainsString('Rs.192400.00', $text);
    }

    public function test_service_pos_pdf_keeps_non_sku_service_description_unchanged(): void
    {
        $payload = new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-SVC-SKU-TEST',
            issuedAt: '2026-09-22 12:00:00',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: '1312, Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            buyerName: 'Service Buyer',
            buyerGstin: '36AAECA0303M1Z9',
            billingAddress: 'Plot No, 23, Block no, 32,Auto Nagar',
            placeOfSupply: 'Telangana',
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
            channel: StatutoryInvoiceChannel::DeskService->value,
            paymentReference: 'ATFL/MAN/15373',
            orderId: 'SVC-675',
        );

        $text = $this->extractedPdfText((new SimplePdfRenderer)->render($payload));

        $this->assertStringContainsString('Secondary Freight Reverse Auction', $text);
        $this->assertStringContainsString('998311', $text);
        $this->assertStringContainsString('Rs.59000.00', $text);
    }

    public function test_product_pos_integration_pdf_omits_sku_but_preserves_financial_columns(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'MFS110-SKU-TEST',
            'name' => 'Mantra MFS 110 L1 Single Fingerprint Biometric Scanner',
            'hsn_code' => '84716050',
            'uqc' => 'PCS',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($product, $this->branch, ['SN-SKU-TEST-1'], $this->actor);

        $sale = app(PosSaleService::class)->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Walk-in Buyer', 'phone' => '9000002500'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['SN-SKU-TEST-1'],
            ]],
            paymentMethod: 'UPI',
            paymentReference: 'UPI-SKU-TEST',
            actor: $this->actor,
            statutory: [
                'place_of_supply_state' => 'Delhi',
                'billing_address' => '1 Test Street, Delhi',
                'billing_city' => 'New Delhi',
                'billing_state' => 'Delhi',
                'billing_pincode' => '110001',
            ],
        );

        $invoice = StatutoryInvoice::query()->where('inventory_sale_id', $sale->id)->firstOrFail();
        $this->assertStringContainsString('MFS110-SKU-TEST', (string) $invoice->items->first()?->description);

        $binary = $this->invoicePdf($invoice);
        $text = $this->extractedPdfText($binary);

        $this->assertStringContainsString('Mantra MFS 110 L1', $text);
        $this->assertStringNotContainsString('MFS110-SKU-TEST', $text);
        $this->assertStringContainsString('84716050', $text);
        $this->assertStringContainsString('Reference No.', $text);
        $this->assertStringContainsString('UPI-SKU-TEST', $text);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array{label: string, serials: list<string>}>  $serialGroups
     */
    private function hardwarePayload(array $lines, array $serialGroups = []): StatutoryInvoicePdfPayload
    {
        $serialNumbers = [];
        foreach ($serialGroups as $group) {
            foreach ($group['serials'] as $serial) {
                $serialNumbers[] = $serial;
            }
        }

        return new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-SKU-TEST',
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
            taxableValue: '192400.00',
            gstRate: '18.00%',
            taxTotal: '34632.00',
            cgst: '17316.00',
            sgst: '17316.00',
            igst: '0.00',
            invoiceValue: '227032.00',
            serialNumbers: $serialNumbers,
            serialGroups: $serialGroups,
            channel: StatutoryInvoiceChannel::DeskPos->value,
            orderId: 'POS-6735',
        );
    }

    private function invoicePdf(StatutoryInvoice $invoice): string
    {
        $document = StatutoryInvoiceDocument::query()->where('invoice_id', $invoice->id)->firstOrFail();

        return app(StatutoryDocumentService::class)->binary($document);
    }
}
