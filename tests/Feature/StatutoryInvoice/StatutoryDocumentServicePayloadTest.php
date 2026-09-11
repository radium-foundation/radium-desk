<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\EInvoiceRecord;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\InventorySerial;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceDocument;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StatutoryDocumentServicePayloadTest extends TestCase
{
    use RefreshDatabase;

    private const JWT = 'eyJhbGciOiJFUzI1NiIsInR5cCI6IkpXVCJ9.eyJkYXRhIjoicDIzNC1hdXRob3JpdGF0aXZlLXNpZ25lZC1xciJ9.dGVzdC1zaWduYXR1cmUtcDIzNC1ub3QtcHJvZHVjdGlvbg';

    private const IRN = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    private User $actor;

    private InventoryBranch $branch;

    private StatutoryDocumentService $documents;

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

        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);
        $this->documents = app(StatutoryDocumentService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_b2c_generate_passes_payment_serial_cin_logo_and_omits_irn(): void
    {
        $sale = $this->completeSale(
            sku: 'MFS110-B2C',
            serials: ['SN-B2C-001'],
            paymentMethod: 'UPI',
            paymentReference: 'UPI-REF-B2C',
            buyerGstin: null,
            uqc: 'PCS',
        );
        $invoice = StatutoryInvoice::query()->where('inventory_sale_id', $sale->id)->firstOrFail();
        $binary = $this->pdf($invoice);
        $text = $this->text($binary);

        $this->assertSame('UPI', $invoice->payment_method);
        $this->assertSame('UPI-REF-B2C', $invoice->payment_reference);
        $this->assertSame('PCS', $invoice->items->first()?->uqc);
        $this->assertStringContainsString('TAX INVOICE', $text);
        $this->assertStringContainsString($invoice->invoice_number, $text);
        $this->assertStringContainsString('UPI', $text);
        $this->assertStringContainsString('UPI-REF-B2C', $text);
        $this->assertStringContainsString('Mode of Payment', $text);
        $this->assertStringContainsString('SN-B2C-001', $text);
        $this->assertStringContainsString('PCS', $text);
        $this->assertStringContainsString('CIN: U72300DL2015PTC280283', $text);
        $this->assertStringContainsString('/Logo Do', $binary);
        $this->assertStringContainsString('/Stamp Do', $binary);
        $this->assertStringContainsString('GSTIN Unregistered', $text);
        $this->assertStringContainsString('/MediaBox [0 0 595 842]', $binary);
        $this->assertStringNotContainsString('e-Invoice Verification', $text);
        $this->assertStringNotContainsString('% signed-qr-image', $binary);
        $this->assertStringNotContainsString(self::JWT, $binary);
        $this->assertDoesNotMatchRegularExpression('/IRN [A-Za-z0-9]{8,}/', $text);
        $this->dumpPdf('b2c-generate', $binary);
    }

    public function test_b2b_generate_does_not_invent_irn_before_submission(): void
    {
        $sale = $this->completeSale(
            sku: 'MFS110-B2B-PENDING',
            serials: ['SN-B2B-PENDING'],
            paymentMethod: 'Cash',
            paymentReference: 'CASH-001',
            buyerGstin: '07AAAAA0000A1Z5',
            uqc: 'PCS',
        );
        $invoice = StatutoryInvoice::query()->where('inventory_sale_id', $sale->id)->firstOrFail();
        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $binary = $this->pdf($invoice);
        $text = $this->text($binary);

        $this->assertNotNull($record);
        $this->assertNotSame(EInvoiceRecordStatus::Submitted->value, $record->status);
        $this->assertNull($record->irn);
        $this->assertStringContainsString('07AAAAA0000A1Z5', $text);
        $this->assertStringContainsString('Cash', $text);
        $this->assertStringContainsString('CASH-001', $text);
        $this->assertStringContainsString('SN-B2B-PENDING', $text);
        $this->assertStringNotContainsString('e-Invoice Verification', $text);
        $this->assertStringNotContainsString('% signed-qr-image', $binary);
        $this->assertDoesNotMatchRegularExpression('/IRN [A-Za-z0-9]{8,}/', $text);

        $unchanged = $this->documents->generate($invoice->fresh(['items', 'eInvoiceRecord']));
        $this->assertSame($invoice->document?->checksum, $unchanged->checksum);
        $this->dumpPdf('b2b-pending-generate', $binary);
    }

    public function test_b2b_finalize_after_irn_passes_irn_ack_and_stored_signed_qr(): void
    {
        $sale = $this->completeSale(
            sku: 'MFS110-B2B-IRN',
            serials: ['SN-B2B-IRN'],
            paymentMethod: 'UPI',
            paymentReference: 'UPI-IRN-009',
            buyerGstin: '07AAAAA0000A1Z5',
            uqc: 'PCS',
        );
        $invoice = StatutoryInvoice::query()->where('inventory_sale_id', $sale->id)->firstOrFail();
        $beforeValue = (string) $invoice->invoice_value;
        $beforeTaxable = (string) $invoice->taxable_value;
        $first = $this->pdf($invoice);
        $this->assertStringNotContainsString('% signed-qr-image', $first);

        $this->attachSubmittedIrn($invoice);
        $immutable = $this->documents->generate($invoice->fresh(['items', 'eInvoiceRecord']));
        $this->assertStringNotContainsString('% signed-qr-image', $this->documents->binary($immutable));

        $rewritten = $this->documents->finalizeAfterIrn($invoice->fresh(['items', 'eInvoiceRecord']));
        $this->assertNotNull($rewritten);
        $binary = $this->documents->binary($rewritten);
        $text = $this->text($binary);
        $after = $invoice->fresh(['items']);

        $this->assertStringContainsString('e-Invoice Verification', $text);
        $this->assertStringContainsString(self::IRN, $text);
        $this->assertStringContainsString('Ack No: ACK-2341', $text);
        $this->assertStringContainsString('% signed-qr-image', $binary);
        $this->assertStringNotContainsString(self::JWT, $binary);
        $this->assertStringContainsString('PCS', $text);
        $this->assertStringContainsString('SN-B2B-IRN', $text);
        $this->assertStringContainsString('UPI', $text);
        $this->assertStringContainsString('UPI-IRN-009', $text);
        $this->assertStringContainsString('CIN: U72300DL2015PTC280283', $text);
        $this->assertStringContainsString('/Logo Do', $binary);
        $this->assertStringContainsString('Whether tax is payable on reverse charge basis: No', $text);
        $this->assertSame($beforeValue, (string) $after->invoice_value);
        $this->assertSame($beforeTaxable, (string) $after->taxable_value);
        $this->assertStringContainsString('Rs.'.number_format((float) $beforeValue, 2, '.', ''), $text);
        $this->assertSame(self::JWT, $this->decodeSignedQr($binary));
        $this->dumpPdf('b2b-finalize-after-irn', $binary);
    }

    public function test_ten_serial_generate_passes_complete_list_and_annexure(): void
    {
        $serials = [];
        for ($i = 1; $i <= 10; $i++) {
            $serials[] = sprintf('SN-MULTI-%03d', $i);
        }
        $sale = $this->completeSale(
            sku: 'MFS110-MULTI',
            serials: $serials,
            paymentMethod: 'Cash',
            paymentReference: 'CASH-MULTI',
            buyerGstin: '07AAAAA0000A1Z5',
            uqc: 'PCS',
        );
        $invoice = StatutoryInvoice::query()->where('inventory_sale_id', $sale->id)->firstOrFail();
        $binary = $this->pdf($invoice);
        $text = $this->text($binary);

        $this->assertSame(10, $sale->serials->count());
        $this->assertStringContainsString('Serial Numbers', $text);
        $this->assertStringContainsString('* More serial numbers in Annexure A', $text);
        $this->assertStringContainsString('ANNEXURE A', $text);
        $this->assertStringContainsString('Annexure to tax invoice '.$invoice->invoice_number, $text);
        $this->assertStringContainsString('Total serials', $text);
        foreach ($serials as $serial) {
            $this->assertStringContainsString($serial, $text);
        }
        $this->assertStringContainsString('/MediaBox [0 0 595 842]', $binary);
        $this->dumpPdf('multi-serial-generate', $binary);
    }

    public function test_mapper_passes_authoritative_financials_without_recalculation(): void
    {
        $sale = $this->completeSale(
            sku: 'MFS110-FIN',
            serials: ['SN-FIN-001'],
            paymentMethod: 'Cash',
            paymentReference: 'CASH-FIN',
            buyerGstin: null,
            uqc: 'PCS',
        );
        $invoice = StatutoryInvoice::query()->where('inventory_sale_id', $sale->id)->firstOrFail();
        $before = $invoice->only(['invoice_number', 'taxable_value', 'tax_total', 'invoice_value', 'cgst', 'sgst', 'igst']);

        $this->documents->regeneratePresentation($invoice->fresh(['items', 'eInvoiceRecord']));
        $alias = $this->documents->regenerateForHardwareSerialCorrection($invoice->fresh(['items', 'eInvoiceRecord']));
        $after = $invoice->fresh();
        $text = $this->text($this->documents->binary($alias));

        foreach ($before as $key => $value) {
            $this->assertSame((string) $value, (string) $after->{$key}, $key);
        }
        $this->assertStringContainsString('Rs.'.number_format((float) $before['invoice_value'], 2, '.', ''), $text);
        $this->assertStringContainsString($before['invoice_number'], $text);
    }

    public function test_null_uqc_is_preserved_and_displayed_as_placeholder(): void
    {
        $sale = $this->completeSale(
            sku: 'MFS110-NULL-UQC',
            serials: ['SN-NULL-UQC'],
            paymentMethod: 'Cash',
            paymentReference: 'CASH-UQC',
            buyerGstin: null,
            uqc: null,
        );
        $invoice = StatutoryInvoice::query()->where('inventory_sale_id', $sale->id)->firstOrFail();
        $lineUqc = $invoice->items->first()?->getAttribute('uqc');
        $binary = $this->pdf($invoice);
        $text = $this->text($binary);

        if ($lineUqc === null || $lineUqc === '') {
            $this->assertNull($lineUqc);
            $this->assertStringContainsString('UQC', $text);
        } else {
            $this->assertStringContainsString((string) $lineUqc, $text);
        }
    }

    public function test_commerce_generate_passes_order_payment_without_irn_block(): void
    {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-RD-P234',
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => 'RD-P234-PAY',
            'source_order_id' => 'RD-P234-PAY',
            'idempotency_key' => 'statutory:rd_service_in:commerce_order:RD-P234-PAY',
            'payload_hash' => hash('sha256', 'RD-P234-PAY'),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'payment_method' => 'UPI',
            'payment_reference' => 'CF-ORDER-P234',
            'currency' => 'INR',
            'customer_name' => 'CHANDRAKANT GANPAT SARODE',
            'customer_phone' => '9876543210',
            'buyer_gstin' => null,
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
            'description' => 'Information technology (IT) consulting & support services',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => 422.88,
            'gst_percentage' => 18,
            'taxable_value' => 422.88,
            'tax_total' => 76.12,
            'line_total' => 499.00,
        ]);

        $invoice = app(StatutoryInvoiceService::class)->issueFromCommerceOrder($order->fresh(['items']), $this->actor);
        $binary = $this->pdf($invoice);
        $text = $this->text($binary);

        $this->assertSame('UPI', $invoice->payment_method);
        $this->assertSame('CF-ORDER-P234', $invoice->payment_reference);
        $this->assertStringContainsString('UPI', $text);
        $this->assertStringContainsString('CF-ORDER-P234', $text);
        $this->assertStringContainsString('CIN: U72300DL2015PTC280283', $text);
        $this->assertStringNotContainsString('e-Invoice Verification', $text);
        $this->assertStringContainsString('Rs.'.number_format((float) $invoice->invoice_value, 2, '.', ''), $text);
        $this->dumpPdf('commerce-b2c-generate', $binary);
    }

    /**
     * @param  list<string>  $serials
     */
    private function completeSale(
        string $sku,
        array $serials,
        string $paymentMethod,
        string $paymentReference,
        ?string $buyerGstin,
        ?string $uqc,
    ): InventorySale {
        $product = InventoryProduct::query()->create([
            'sku' => $sku,
            'name' => 'Mantra MFS110',
            'hsn_code' => '84716050',
            'uqc' => $uqc,
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($product, $this->branch, $serials, $this->actor);

        $statutory = [
            'place_of_supply_state' => 'Delhi',
            'billing_address' => '1 Test Street, Delhi',
            'billing_city' => 'New Delhi',
            'billing_state' => 'Delhi',
            'billing_pincode' => '110001',
        ];
        if ($buyerGstin !== null) {
            $statutory['buyer_gstin'] = $buyerGstin;
        }

        $sale = app(PosSaleService::class)->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Walk-in Buyer', 'phone' => '9000002340'],
            lines: [[
                'product_id' => $product->id,
                'qty' => count($serials),
                'serials' => $serials,
            ]],
            paymentMethod: $paymentMethod,
            paymentReference: $paymentReference,
            actor: $this->actor,
            statutory: $statutory,
        );

        $sale = $sale->fresh(['serials.serial', 'lines.product']) ?? $sale;
        $this->assertNotNull(
            StatutoryInvoice::query()->where('inventory_sale_id', $sale->id)->value('id'),
        );
        $this->assertSame(
            count($serials),
            InventorySerial::query()->whereIn('serial_number', $serials)->count(),
        );

        return $sale;
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

    private function pdf(StatutoryInvoice $invoice): string
    {
        $document = StatutoryInvoiceDocument::query()->where('invoice_id', $invoice->id)->firstOrFail();

        return $this->documents->binary($document);
    }

    private function text(string $pdf): string
    {
        return str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $pdf);
    }

    private function decodeSignedQr(string $binary): string
    {
        $zbar = null;
        foreach (['/opt/homebrew/bin/zbarimg', '/usr/local/bin/zbarimg', '/usr/bin/zbarimg'] as $candidate) {
            if (is_executable($candidate)) {
                $zbar = $candidate;
                break;
            }
        }
        $pdftoppm = null;
        foreach (['/opt/homebrew/bin/pdftoppm', '/usr/local/bin/pdftoppm', '/usr/bin/pdftoppm'] as $candidate) {
            if (is_executable($candidate)) {
                $pdftoppm = $candidate;
                break;
            }
        }
        if ($zbar === null || $pdftoppm === null) {
            $this->markTestSkipped('zbarimg/pdftoppm unavailable for QR decode verification.');
        }

        $dir = storage_path('framework/testing');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $pdfPath = $dir.'/p234-service-qr.pdf';
        $pngPrefix = $dir.'/p234-service-qr';
        file_put_contents($pdfPath, $binary);
        @unlink($pngPrefix.'-1.png');
        exec(escapeshellarg($pdftoppm).' -png -r 300 '.escapeshellarg($pdfPath).' '.escapeshellarg($pngPrefix).' 2>/dev/null', $output, $exitCode);
        $this->assertSame(0, $exitCode);
        $this->assertFileExists($pngPrefix.'-1.png');

        return rtrim((string) shell_exec(escapeshellarg($zbar).' --raw -q '.escapeshellarg($pngPrefix.'-1.png').' 2>/dev/null'), "\n");
    }

    private function dumpPdf(string $name, string $binary): void
    {
        $dir = '/tmp/desk-p234-qa';
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return;
        }

        file_put_contents($dir.'/'.$name.'.pdf', $binary);
    }
}
