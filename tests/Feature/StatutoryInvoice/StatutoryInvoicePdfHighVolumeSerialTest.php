<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Services\StatutoryInvoice\Data\StatutoryInvoicePdfPayload;
use App\Services\StatutoryInvoice\SimplePdfRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsStatutoryInvoicePdfSerials;
use Tests\TestCase;

class StatutoryInvoicePdfHighVolumeSerialTest extends TestCase
{
    use AssertsStatutoryInvoicePdfSerials;

    private const IRN = '632fdabeceaad34a5dc5c6782c0ec471bae2d88f8efb8eb6b82747dba3f919b1';

    #[DataProvider('highVolumeIrnCounts')]
    public function test_high_volume_irn_invoices_preserve_complete_ordered_serial_set(int $count): void
    {
        $serials = $this->serialList($count);
        $jwt = $this->jwtSignedQr();

        $binary = (new SimplePdfRenderer)->render($this->hardwarePayload(
            serials: $serials,
            withIrn: true,
            invoiceNumber: 'INV-HV-IRN-'.$count,
            orderId: 'POS-HV-IRN-'.$count,
            signedQr: $jwt,
        ));
        $text = $this->pdfText($binary);

        $this->assertOptionBWithAnnexure($binary, $serials);
        $this->assertStringContainsString('ANNEXURE A', $text);
        $this->assertStringContainsString('Complete serial-number list provided in Annexure A.', $text);
        $this->assertGreaterThanOrEqual(2, $this->pdfPageCount($binary));
        $this->assertStringContainsString('Rs.11800.00', $text);
        $this->assertStringContainsString('Rs.1800.00', $text);
        $this->assertVerificationBlockOnPageOne($binary, $jwt);
        $this->assertStringContainsString(self::IRN, $text);
        $this->assertStringContainsString('Ack No: 172621204889923', $text);
    }

    #[DataProvider('highVolumeNonIrnCounts')]
    public function test_high_volume_non_irn_invoices_preserve_complete_ordered_serial_set(int $count): void
    {
        $serials = $this->serialList($count);

        $binary = (new SimplePdfRenderer)->render($this->hardwarePayload(
            serials: $serials,
            withIrn: false,
            invoiceNumber: 'INV-HV-PLAIN-'.$count,
            orderId: 'POS-HV-PLAIN-'.$count,
        ));
        $text = $this->pdfText($binary);

        $this->assertOptionBWithAnnexure($binary, $serials);
        $this->assertStringContainsString('ANNEXURE A', $text);
        $this->assertGreaterThanOrEqual(2, $this->pdfPageCount($binary));
        $this->assertStringNotContainsString('e-Invoice Verification', $text);
        $this->assertStringContainsString('Rs.11800.00', $text);
    }

    public function test_annexure_pages_scale_beyond_one_hundred_serials_without_dropping(): void
    {
        $count = 250;
        $serials = $this->serialList($count);

        $binary = (new SimplePdfRenderer)->render($this->hardwarePayload(
            serials: $serials,
            withIrn: true,
            invoiceNumber: 'INV-HV-ANNEX-250',
            orderId: 'POS-HV-ANNEX-250',
            signedQr: $this->jwtSignedQr(),
        ));
        $text = $this->pdfText($binary);

        $this->assertSame(2, $this->pdfPageCount($binary));
        $this->assertOptionBWithAnnexure($binary, $serials);
        $this->assertStringContainsString('Total serials', $text);
        $this->assertStringContainsString((string) $count, $text);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function highVolumeIrnCounts(): array
    {
        return [
            '150 serials with IRN' => [150],
            '200 serials with IRN' => [200],
            '250 serials with IRN' => [250],
        ];
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function highVolumeNonIrnCounts(): array
    {
        return [
            '200 serials without IRN' => [200],
            '250 serials without IRN' => [250],
        ];
    }

    /**
     * @return list<string>
     */
    private function serialList(int $count): array
    {
        $serials = [];
        for ($i = 1; $i <= $count; $i++) {
            $serials[] = sprintf('HV-%05d', $i);
        }

        return $serials;
    }

    /**
     * @param  list<string>  $serials
     */
    private function hardwarePayload(
        array $serials,
        bool $withIrn,
        string $invoiceNumber,
        string $orderId,
        ?string $signedQr = null,
    ): StatutoryInvoicePdfPayload {
        $count = count($serials);

        return new StatutoryInvoicePdfPayload(
            invoiceNumber: $invoiceNumber,
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
                'qty' => $count,
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
            orderId: $orderId,
            irn: $withIrn ? self::IRN : null,
            ackNo: $withIrn ? '172621204889923' : null,
            ackDate: $withIrn ? '2026-09-18 17:56:00' : null,
            signedQr: $signedQr,
        );
    }

    private function jwtSignedQr(): string
    {
        return 'eyJhbGciOiJFUzI1NiIsInR5cCI6IkpXVCJ9.eyJkYXRhIjoiZWluaXZvaWNlLXRlc3QtcGF5bG9hZC1maXh0dXJlIn0.dGVzdC1zaWduYXR1cmUtZml4dHVyZS1ub3QtcHJvZHVjdGlvbg';
    }

    private function assertVerificationBlockOnPageOne(string $binary, ?string $expectedSignedQr = null): void
    {
        $pdfPath = storage_path('framework/testing/page1-verify-'.uniqid('', true).'.pdf');
        file_put_contents($pdfPath, $binary);

        $pageOneText = trim((string) shell_exec(
            escapeshellarg($this->pdftotextBinary()).' -f 1 -l 1 '.escapeshellarg($pdfPath).' - 2>/dev/null'
        ));
        $pageTwoText = '';
        if ($this->pdfPageCount($binary) >= 2) {
            $pageTwoText = trim((string) shell_exec(
                escapeshellarg($this->pdftotextBinary()).' -f 2 -l 2 '.escapeshellarg($pdfPath).' - 2>/dev/null'
            ));
        }

        $this->assertStringContainsString('e-Invoice Verification', $pageOneText, 'Page 1 must contain e-Invoice Verification.');
        $this->assertStringContainsString('Authorized Signatory', $pageOneText, 'Page 1 must contain Authorized Signatory.');
        $this->assertStringContainsString(self::IRN, $pageOneText, 'Page 1 must contain the IRN.');
        $this->assertStringContainsString('Ack No: 172621204889923', $pageOneText, 'Page 1 must contain Ack No.');
        if ($pageTwoText !== '') {
            $this->assertStringNotContainsString(
                'e-Invoice Verification',
                $pageTwoText,
                'e-Invoice Verification must not appear on page 2.',
            );
            $this->assertStringNotContainsString(
                'Authorized Signatory',
                $pageTwoText,
                'Authorized Signatory must not appear on page 2.',
            );
        }

        if ($expectedSignedQr !== null && str_contains($binary, '% signed-qr-image')) {
            $pdftoppm = $this->pdftoppmBinary();
            $zbar = $this->zbarimgBinary();
            if ($pdftoppm !== null && $zbar !== null) {
                $pngPrefix = storage_path('framework/testing/page1-qr-'.uniqid('', true));
                $command = escapeshellarg($pdftoppm).' -png -r 300 -f 1 -l 1 '
                    .escapeshellarg($pdfPath).' '.escapeshellarg($pngPrefix).' 2>/dev/null';
                exec($command, $output, $exitCode);
                $pngFile = $pngPrefix.'-1.png';
                $this->assertSame(0, $exitCode, 'Expected pdftoppm to rasterize page 1 for QR verification.');
                $this->assertFileExists($pngFile, 'Expected page-1 PNG for QR verification.');

                $decodeCommand = escapeshellarg($zbar).' --raw -q '.escapeshellarg($pngFile);
                $decoded = trim((string) shell_exec($decodeCommand.' 2>/dev/null'));
                $this->assertSame($expectedSignedQr, $decoded, 'Page-1 QR payload must match the signed e-invoice JWT.');

                @unlink($pngFile);
            }
        }

        @unlink($pdfPath);
    }

    private function pdftotextBinary(): string
    {
        foreach (['/opt/homebrew/bin/pdftotext', '/usr/local/bin/pdftotext', '/usr/bin/pdftotext'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        $this->fail('pdftotext is required for page-aware verification-block assertions.');
    }

    private function pdftoppmBinary(): ?string
    {
        foreach (['/opt/homebrew/bin/pdftoppm', '/usr/local/bin/pdftoppm', '/usr/bin/pdftoppm'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function zbarimgBinary(): ?string
    {
        foreach (['/opt/homebrew/bin/zbarimg', '/usr/local/bin/zbarimg', '/usr/bin/zbarimg'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
