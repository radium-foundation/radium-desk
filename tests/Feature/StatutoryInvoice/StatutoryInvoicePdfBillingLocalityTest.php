<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\EInvoiceRecord;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Services\StatutoryInvoice\Data\StatutoryInvoicePdfPayload;
use App\Services\StatutoryInvoice\SimplePdfRenderer;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StatutoryInvoicePdfBillingLocalityTest extends TestCase
{
    use RefreshDatabase;

    private const JWT = 'eyJhbGciOiJFUzI1NiIsInR5cCI6IkpXVCJ9.eyJkYXRhIjoicDIzNC1hdXRob3JpdGF0aXZlLXNpZ25lZC1xciJ9.dGVzdC1zaWduYXR1cmUtcDIzNC1ub3QtcHJvZHVjdGlvbg';

    private const IRN = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->configureLocationSellerIdentity();
        config([
            'statutory_invoices.contact_email' => 'mail@radiumbox.com',
            'statutory_invoices.contact_phone' => '+91-84343 84343',
            'statutory_invoices.legal_name' => 'Phil Technologies (P) Limited',
            'statutory_invoices.einvoice.provider' => 'none',
        ]);
    }

    public function test_structured_city_state_pin_render_when_street_is_line1_only(): void
    {
        $invoice = $this->serviceInvoiceShape();

        app(StatutoryDocumentService::class)->generate($invoice);
        $text = $this->pdfText(Storage::disk('local')->get('statutory-invoices/'.$invoice->id.'.pdf'));

        $this->assertStringContainsString('Pune, Maharashtra 410501', $text);
        $this->assertStringContainsString('Gat No. 148/3 to 148/7', $text);
    }

    public function test_combined_billing_address_does_not_duplicate_locality_line(): void
    {
        $combined = '312 dhamankar plaza, Bhiwandi, Thane, Maharashtra, 421305';
        $payload = new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-DEDUP',
            issuedAt: '2026-10-28 18:04:31',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: 'Delhi',
            sellerState: 'Delhi',
            buyerName: 'Buyer',
            buyerGstin: '27AAAAA0000A1Z5',
            billingAddress: $combined,
            placeOfSupply: 'Maharashtra',
            lines: [[
                'description' => 'Service',
                'hsnSac' => '998312',
                'qty' => 1,
                'unitPrice' => '100.00',
                'taxableValue' => '100.00',
                'gstPercentage' => '18.00',
                'cgst' => '0.00',
                'sgst' => '0.00',
                'igst' => '18.00',
                'taxTotal' => '18.00',
                'lineTotal' => '118.00',
            ]],
            taxableValue: '100.00',
            gstRate: '18.00',
            taxTotal: '18.00',
            cgst: '0.00',
            sgst: '0.00',
            igst: '18.00',
            invoiceValue: '118.00',
            billingLocalityLine: StatutoryBillingStructured::pdfLocalityLine(
                [
                    'line1' => '312 dhamankar plaza',
                    'city' => 'Bhiwandi',
                    'state' => 'Maharashtra',
                    'pincode' => '421305',
                ],
                $combined,
            ),
        );

        $this->assertNull($payload->billingLocalityLine);
        $text = $this->pdfText((new SimplePdfRenderer)->render($payload));
        $this->assertSame(1, substr_count($text, '421305'));
    }

    public function test_irn_ack_and_place_of_supply_remain_on_pdf_with_locality_line(): void
    {
        $invoice = $this->serviceInvoiceShape();
        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'fake',
            'status' => EInvoiceRecordStatus::Submitted->value,
            'irn' => self::IRN,
            'ack_no' => '172621540805805',
            'ack_date' => '2026-10-28 23:50:00',
            'signed_qr' => self::JWT,
        ]);

        $document = app(StatutoryDocumentService::class)->finalizeAfterIrn($invoice->fresh(['items', 'eInvoiceRecord']));
        $binary = Storage::disk('local')->get((string) $document->path);
        $text = $this->pdfText($binary);

        $this->assertStringContainsString(self::IRN, $text);
        $this->assertStringContainsString('172621540805805', $text);
        $this->assertStringContainsString('Place of Supply Maharashtra', $text);
        $this->assertStringContainsString('Pune, Maharashtra 410501', $text);
    }

    private function serviceInvoiceShape(): StatutoryInvoice
    {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-0767652-TEST',
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::DeskService,
            'source_type' => StatutoryInvoiceSourceType::ServiceOrder,
            'source_id' => 'SVC-680-TEST',
            'idempotency_key' => 'statutory:pdf-locality-test',
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => 'Buyer Industries',
            'buyer_gstin' => '27AAAAA0000A1Z5',
            'billing_address' => 'Gat No. 148/3 to 148/7, Chakan Talegaon Road, Mahalunge',
            'billing_address_structured' => [
                'line1' => 'Gat No. 148/3 to 148/7, Chakan Talegaon Road, Mahalunge',
                'city' => 'Pune',
                'state' => 'Maharashtra',
                'pincode' => '410501',
            ],
            'place_of_supply_state' => 'Maharashtra',
            'taxable_value' => '95000.00',
            'discount' => '0.00',
            'tax_total' => '17100.00',
            'cgst' => '0.00',
            'sgst' => '0.00',
            'igst' => '17100.00',
            'rounding' => '0.00',
            'invoice_value' => '112100.00',
            'issued_at' => '2026-10-28 18:04:31',
        ]);

        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'Consultancy Fees',
            'hsn_sac' => '998312',
            'qty' => 1,
            'unit_price' => '95000.00',
            'discount' => '0.00',
            'gst_percentage' => '18.00',
            'taxable_value' => '95000.00',
            'tax_total' => '17100.00',
            'cgst' => '0.00',
            'sgst' => '0.00',
            'igst' => '17100.00',
            'line_total' => '112100.00',
        ]);

        return $invoice->fresh(['items']) ?? $invoice;
    }

    private function pdfText(string $binary): string
    {
        $path = tempnam(sys_get_temp_dir(), 'statutory-pdf-');
        if ($path === false) {
            $this->fail('Could not create temp file for PDF text extraction.');
        }

        file_put_contents($path, $binary);
        $text = shell_exec('pdftotext -layout '.escapeshellarg($path).' - 2>/dev/null') ?? '';
        @unlink($path);

        return $text;
    }
}
