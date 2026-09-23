<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\StatutoryInvoiceChannel;
use App\Services\StatutoryInvoice\Data\StatutoryInvoicePdfPayload;
use App\Services\StatutoryInvoice\SimplePdfRenderer;
use Tests\Support\AssertsStatutoryInvoicePdfSerials;
use Tests\TestCase;

class StatutoryInvoicePdfPage1LayoutTest extends TestCase
{
    use AssertsStatutoryInvoicePdfSerials;

    private const IRN = '632fdabeceaad34a5dc5c6782c0ec471bae2d88f8efb8eb6b82747dba3f919b1';

    public function test_zero_serials_skip_serial_and_annexure_blocks(): void
    {
        $binary = $this->render($this->payload(serials: [], withIrn: false));

        $this->assertStringNotContainsString('ANNEXURE A', $binary);
        $this->assertStringNotContainsString(SimplePdfRenderer::ANNEXURE_NOTICE_TEXT, $binary);
        $this->assertMainInvoiceFooterOnFirstPage($binary);
        $this->assertA4PageDimensions($binary);
    }

    public function test_one_short_serial_renders_inline_on_page_one(): void
    {
        $serials = ['10950815'];
        $binary = $this->render($this->payload($serials, withIrn: false));

        $this->assertOptionBMainPageOnly($binary, $serials);
        $this->assertMainInvoiceFooterOnFirstPage($binary);
        $this->assertA4PageDimensions($binary);
    }

    public function test_ten_short_serials_render_inline_when_measured_block_fits(): void
    {
        $serials = $this->shortSerialList(10);
        $binary = $this->render($this->payload($serials, withIrn: true));

        $this->assertOptionBMainPageOnly($binary, $serials);
        $this->assertMainInvoiceFooterOnFirstPage($binary, self::IRN);
        $this->assertA4PageDimensions($binary);
    }

    public function test_ten_long_serials_move_entire_set_to_annexure_when_block_does_not_fit(): void
    {
        $serials = $this->longSerialList(10);
        $payload = $this->payload($serials, withIrn: true);
        $lines = [];
        for ($i = 1; $i <= 6; $i++) {
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
        $binary = $this->render(new StatutoryInvoicePdfPayload(
            invoiceNumber: $payload->invoiceNumber,
            issuedAt: $payload->issuedAt,
            sellerLegalName: $payload->sellerLegalName,
            sellerGstin: $payload->sellerGstin,
            sellerAddress: $payload->sellerAddress,
            sellerState: $payload->sellerState,
            buyerName: $payload->buyerName,
            buyerGstin: $payload->buyerGstin,
            billingAddress: $payload->billingAddress,
            placeOfSupply: $payload->placeOfSupply,
            lines: $lines,
            taxableValue: $payload->taxableValue,
            gstRate: $payload->gstRate,
            taxTotal: $payload->taxTotal,
            cgst: $payload->cgst,
            sgst: $payload->sgst,
            igst: $payload->igst,
            invoiceValue: $payload->invoiceValue,
            serialNumbers: $serials,
            channel: $payload->channel,
            orderId: $payload->orderId,
            irn: $payload->irn,
            ackNo: $payload->ackNo,
            ackDate: $payload->ackDate,
            signedQr: $payload->signedQr,
        ));

        $this->assertOptionBWithAnnexure($binary, $serials);
        $this->assertA4PageDimensions($binary);
    }

    public function test_eleven_serials_always_use_annexure_with_zero_inline_on_page_one(): void
    {
        $serials = $this->shortSerialList(11);
        $binary = $this->render($this->payload($serials, withIrn: true));

        $this->assertOptionBWithAnnexure($binary, $serials);
        $this->assertA4PageDimensions($binary);
    }

    public function test_long_serial_strings_are_preserved_in_annexure_without_page_one_split(): void
    {
        $serials = [
            'H20033-MP826AH19025507-02/26',
            'H20177-MP826AH19025514-02/26',
            'H20216-MP826AK2B031569-02/26',
        ];
        $binary = $this->render($this->payload($serials, withIrn: false));

        $this->assertOptionBMainPageOnly($binary, $serials);
        $this->assertSerialPresentInPdf($binary, 'H20033-MP826AH19025507-02/26');
    }

    public function test_b2c_without_irn_keeps_footer_on_page_one(): void
    {
        $binary = $this->render($this->payload($this->shortSerialList(3), withIrn: false));

        $this->assertMainInvoiceFooterOnFirstPage($binary);
        $this->assertStringNotContainsString(self::IRN, $this->mainPageExtractedText($this->extractedPdfText($binary)));
    }

    public function test_b2b_with_irn_keeps_verification_block_on_page_one(): void
    {
        $binary = $this->render($this->payload($this->shortSerialList(3), withIrn: true));

        $this->assertMainInvoiceFooterOnFirstPage($binary, self::IRN);
        $this->assertStringContainsString('e-Invoice Verification', $this->mainPageExtractedText($this->extractedPdfText($binary)));
    }

    public function test_long_product_descriptions_keep_footer_on_page_one(): void
    {
        $lines = [];
        for ($i = 1; $i <= 8; $i++) {
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

        $binary = $this->render(new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-LONG-LINES',
            issuedAt: '2026-09-23 18:35:54',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: '1312, Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            buyerName: 'VARTC',
            buyerGstin: '09AAJFV1437D1Z7',
            billingAddress: 'Azamgarh, Uttar Pradesh',
            placeOfSupply: 'Uttar Pradesh',
            lines: $lines,
            taxableValue: '800.00',
            gstRate: '18.00%',
            taxTotal: '144.00',
            cgst: '0.00',
            sgst: '0.00',
            igst: '144.00',
            invoiceValue: '944.00',
            serialNumbers: $this->shortSerialList(120),
            irn: self::IRN,
            ackNo: '172621241989060',
            ackDate: '2026-09-23 18:36:00',
            signedQr: $this->signedQr(),
        ));

        $this->assertMainInvoiceFooterOnFirstPage($binary, self::IRN);
        $this->assertOptionBWithAnnexure($binary, $this->shortSerialList(120));
    }

    public function test_multiline_customer_address_keeps_footer_on_page_one(): void
    {
        $binary = $this->render(new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-LONG-ADDR',
            issuedAt: '2026-09-23 18:35:54',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: '1312, Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            buyerName: 'Very Long Enterprise Customer Name Private Limited',
            buyerGstin: '09AAJFV1437D1Z7',
            billingAddress: 'C/o Ganesh Prasad, Ward 12, Civil Lines, Near District Hospital, Azamgarh, Uttar Pradesh, 276001, India',
            placeOfSupply: 'Uttar Pradesh',
            lines: [[
                'description' => 'RBUGR89GPS — RADIUM UGR86 89-NaviC UIDAI Approved GPS for AADHAAR',
                'hsnSac' => '85269190',
                'qty' => 12,
                'unitPrice' => '1800.00',
                'taxableValue' => '21600.00',
                'gstPercentage' => '18.00%',
                'cgst' => '0.00',
                'sgst' => '0.00',
                'igst' => '3888.00',
                'taxTotal' => '3888.00',
                'lineTotal' => '25488.00',
                'uqc' => 'PCS',
            ]],
            taxableValue: '21600.00',
            gstRate: '18.00%',
            taxTotal: '3888.00',
            cgst: '0.00',
            sgst: '0.00',
            igst: '3888.00',
            invoiceValue: '25488.00',
            serialNumbers: $this->shortSerialList(12),
            irn: self::IRN,
            ackNo: '172621241989060',
            ackDate: '2026-09-23 18:36:00',
            signedQr: $this->signedQr(),
        ));

        $this->assertMainInvoiceFooterOnFirstPage($binary, self::IRN);
        $this->assertOptionBWithAnnexure($binary, $this->shortSerialList(12));
    }

    public function test_annexure_preserves_serial_order_without_duplicates(): void
    {
        $serials = $this->shortSerialList(25);
        $binary = $this->render($this->payload($serials, withIrn: true));

        $this->assertOptionBWithAnnexure($binary, $serials);
        foreach ($serials as $serial) {
            $decoded = $this->pdfText($binary);
            $count = substr_count($decoded, $serial);

            $this->assertSame(1, $count, "Serial {$serial} must appear exactly once.");
        }
    }

    /**
     * @param  list<string>  $serials
     */
    private function payload(array $serials, bool $withIrn): StatutoryInvoicePdfPayload
    {
        return new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-PAGE1-LAYOUT',
            issuedAt: '2026-09-23 18:35:54',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: '1312, Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            buyerName: 'Hardware Buyer',
            buyerGstin: '07AAAAA0000A1Z5',
            billingAddress: '1 Test Street, Delhi',
            placeOfSupply: 'Delhi',
            lines: [[
                'description' => 'RBUGR89GPS — RADIUM UGR86 89-NaviC UIDAI Approved GPS for AADHAAR',
                'hsnSac' => '85269190',
                'qty' => max(count($serials), 1),
                'unitPrice' => '1800.00',
                'taxableValue' => '216000.00',
                'gstPercentage' => '18.00%',
                'cgst' => '0.00',
                'sgst' => '0.00',
                'igst' => '38880.00',
                'taxTotal' => '38880.00',
                'lineTotal' => '254880.00',
                'uqc' => 'PCS',
            ]],
            taxableValue: '216000.00',
            gstRate: '18.00%',
            taxTotal: '38880.00',
            cgst: '0.00',
            sgst: '0.00',
            igst: '38880.00',
            invoiceValue: '254880.00',
            serialNumbers: $serials,
            channel: StatutoryInvoiceChannel::DeskPos->value,
            orderId: 'POS-PAGE1-LAYOUT',
            irn: $withIrn ? self::IRN : null,
            ackNo: $withIrn ? '172621204889923' : null,
            ackDate: $withIrn ? '2026-09-21 18:25:00' : null,
            signedQr: $withIrn ? $this->signedQr() : null,
        );
    }

    private function render(StatutoryInvoicePdfPayload $payload): string
    {
        return (new SimplePdfRenderer)->render($payload);
    }

    /**
     * @return list<string>
     */
    private function shortSerialList(int $count): array
    {
        $serials = [];
        for ($i = 1; $i <= $count; $i++) {
            $serials[] = sprintf('109%05d', 50800 + $i);
        }

        return $serials;
    }

    /**
     * @return list<string>
     */
    private function longSerialList(int $count): array
    {
        $serials = [];
        for ($i = 1; $i <= $count; $i++) {
            $serials[] = sprintf(
                'H%05d-MP826D81704%04d-09/26',
                22000 + $i - 1,
                2835 + ($i * 17) % 10000,
            );
        }

        return $serials;
    }

    private function signedQr(): string
    {
        return 'eyJhbGciOiJFUzI1NiIsInR5cCI6IkpXVCJ9.eyJkYXRhIjoiZWluaXZvaWNlLXRlc3QtcGF5bG9hZC1maXh0dXJlIn0.dGVzdC1zaWduYXR1cmUtZml4dHVyZS1ub3QtcHJvZHVjdGlvbg';
    }
}
