<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Services\StatutoryInvoice\Data\StatutoryInvoicePdfPayload;
use App\Services\StatutoryInvoice\SimplePdfRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsStatutoryInvoicePdfSerials;
use Tests\TestCase;

class StatutoryInvoicePdfOptionBSerialLayoutTest extends TestCase
{
    use AssertsStatutoryInvoicePdfSerials;

    private const IRN = '632fdabeceaad34a5dc5c6782c0ec471bae2d88f8efb8eb6b82747dba3f919b1';

    #[DataProvider('mainPageOnlyCounts')]
    public function test_main_page_only_invoices_render_all_serials_without_annexure(int $count): void
    {
        $serials = $this->productionSerialList($count);
        $binary = (new SimplePdfRenderer)->render($this->payload($serials, withIrn: false));
        $text = $this->extractText($binary);

        $this->assertOptionBMainPageOnly($binary, $serials);
        $this->assertStringNotContainsString('ANNEXURE A', $text);
        $this->assertStringNotContainsString('Complete serial-number list provided in Annexure A.', $text);
    }

    #[DataProvider('annexureCounts')]
    public function test_annexure_invoices_repeat_complete_list_and_cap_main_page(int $count): void
    {
        $serials = $this->productionSerialList($count);
        $binary = (new SimplePdfRenderer)->render($this->payload($serials, withIrn: true));
        $text = $this->extractText($binary);

        $this->assertOptionBWithAnnexure($binary, $serials);
        $this->assertStringContainsString('ANNEXURE A', $text);
        $this->assertStringContainsString('Complete serial-number list provided in Annexure A.', $text);
        $this->assertStringContainsString('Total serials', $text);
        $this->assertStringContainsString((string) $count, $text);
    }

    public function test_one_hundred_twenty_production_length_serials_with_irn_render_completely(): void
    {
        $serials = $this->productionSerialList(120);
        $binary = (new SimplePdfRenderer)->render($this->payload(
            serials: $serials,
            withIrn: true,
            invoiceNumber: 'INV-0767202-TEST',
            orderId: 'POS-6731-TEST',
        ));
        $text = $this->extractText($binary);

        $this->assertGreaterThanOrEqual(2, $this->pdfPageCount($binary));
        $this->assertOptionBWithAnnexure($binary, $serials);
        $this->assertStringContainsString('ANNEXURE A', $text);
        $this->assertSerialPresentInPdf($binary, $serials[108]);
        $this->assertSerialPresentInPdf($binary, $serials[119]);
        $this->assertStringContainsString(self::IRN, $text);
    }

    public function test_zero_serial_invoices_skip_serial_blocks(): void
    {
        $binary = (new SimplePdfRenderer)->render($this->payload([], withIrn: false));

        $this->assertStringNotContainsString('ANNEXURE A', $binary);
        $this->assertStringNotContainsString('Complete serial-number list provided in Annexure A.', $binary);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function mainPageOnlyCounts(): array
    {
        return [
            '1 serial' => [1],
            '10 serials' => [10],
        ];
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function annexureCounts(): array
    {
        return [
            '11 serials' => [11],
            '20 serials' => [20],
            '120 serials' => [120],
        ];
    }

    /**
     * @return list<string>
     */
    private function productionSerialList(int $count): array
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

    /**
     * @param  list<string>  $serials
     */
    private function payload(
        array $serials,
        bool $withIrn,
        string $invoiceNumber = 'INV-OPTION-B',
        string $orderId = 'POS-OPTION-B',
    ): StatutoryInvoicePdfPayload {
        return new StatutoryInvoicePdfPayload(
            invoiceNumber: $invoiceNumber,
            issuedAt: '2026-09-21 18:24:02',
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
            orderId: $orderId,
            irn: $withIrn ? self::IRN : null,
            ackNo: $withIrn ? '172621204889923' : null,
            ackDate: $withIrn ? '2026-09-21 18:25:00' : null,
            signedQr: $withIrn ? $this->jwtSignedQr() : null,
        );
    }

    private function extractText(string $pdf): string
    {
        $path = storage_path('framework/testing/option-b-'.uniqid('', true).'.pdf');
        file_put_contents($path, $pdf);
        $text = trim((string) shell_exec(
            escapeshellarg($this->pdftotextBinary()).' '.escapeshellarg($path).' - 2>/dev/null'
        ));
        @unlink($path);

        return $text;
    }

    private function jwtSignedQr(): string
    {
        return 'eyJhbGciOiJFUzI1NiIsInR5cCI6IkpXVCJ9.eyJkYXRhIjoiZWluaXZvaWNlLXRlc3QtcGF5bG9hZC1maXh0dXJlIn0.dGVzdC1zaWduYXR1cmUtZml4dHVyZS1ub3QtcHJvZHVjdGlvbg';
    }

    private function pdftotextBinary(): string
    {
        foreach (['/opt/homebrew/bin/pdftotext', '/usr/local/bin/pdftotext', '/usr/bin/pdftotext'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        $this->fail('pdftotext is required for Option B serial layout assertions.');
    }
}
