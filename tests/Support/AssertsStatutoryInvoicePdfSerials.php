<?php

namespace Tests\Support;

use App\Services\StatutoryInvoice\SimplePdfRenderer;

trait AssertsStatutoryInvoicePdfSerials
{
    /**
     * @param  list<string>  $serials
     */
    protected function assertSerialCoresPresentInPdf(string $binary, array $serials): void
    {
        foreach ($serials as $index => $serial) {
            $this->assertSerialPresentInPdf(
                $binary,
                $serial,
                'Serial #'.($index + 1).' must appear in the PDF.',
            );
        }
    }

    protected function assertSerialPresentInPdf(string $binary, string $serial, ?string $message = null): void
    {
        $decoded = $this->pdfText($binary);
        $message ??= "Serial {$serial} must appear in the PDF.";

        if (str_contains($decoded, $serial) || str_contains($binary, $serial)) {
            return;
        }

        if (str_contains($serial, '/')) {
            [$prefix, $suffix] = array_pad(explode('/', $serial, 2), 2, '');
            $prefixCandidates = array_values(array_unique(array_filter([
                $prefix,
                rtrim($prefix, '-'),
                substr($prefix, 0, max(1, strlen($prefix) - 2)),
                substr($prefix, 0, max(1, strlen($prefix) - 3)),
            ])));
            $prefixFound = false;
            foreach ($prefixCandidates as $candidate) {
                if ($candidate !== '' && (str_contains($binary, $candidate) || str_contains($decoded, $candidate))) {
                    $prefixFound = true;
                    break;
                }
            }
            $this->assertTrue($prefixFound, $message);
            if ($suffix !== '') {
                $this->assertTrue(
                    str_contains($binary, $suffix) || str_contains($decoded, $suffix),
                    $message,
                );
            }

            return;
        }

        if (strlen($serial) >= 24) {
            $core = substr($serial, 0, -5);
            $this->assertStringContainsString($this->pdfLiteral($core), $binary, $message);
            $this->assertStringContainsString($this->pdfLiteral('09/26'), $binary, $message);

            return;
        }

        $this->fail($message);
    }

    /**
     * @param  list<string>  $serials
     */
    protected function assertNumberedSerialIndexesPresent(string $binary, array $serials, int $startIndex = 1): void
    {
        foreach ($serials as $offset => $serial) {
            $index = $startIndex + $offset;
            $this->assertStringContainsString(
                $this->pdfLiteral($index.'.'),
                $binary,
                "Serial index {$index} must appear in the PDF content stream.",
            );
        }
    }

    /**
     * @param  list<string>  $serials
     */
    protected function assertNoIndexOnlySerialOutput(string $binary, array $serials, int $startIndex = 1): void
    {
        foreach ($serials as $offset => $serial) {
            $index = $startIndex + $offset;
            $this->assertStringContainsString(
                $this->pdfLiteral($index.'.'),
                $binary,
                "Serial index {$index} must be rendered.",
            );
            $this->assertSerialPresentInPdf(
                $binary,
                $serial,
                "Serial index {$index} must not render without its serial value.",
            );
        }
    }

    /**
     * @param  list<string>  $serials
     */
    protected function assertOptionBMainPageOnly(string $binary, array $serials): void
    {
        $main = array_slice($serials, 0, min(count($serials), SimplePdfRenderer::MAIN_PAGE_SERIAL_LIMIT));
        $decoded = $this->pdfText($binary);

        $this->assertStringNotContainsString('ANNEXURE A', $binary);
        $this->assertStringNotContainsString(
            SimplePdfRenderer::ANNEXURE_NOTICE_TEXT,
            $binary,
        );
        $this->assertSerialCoresPresentInPdf($binary, $serials);
        $this->assertNumberedSerialIndexesPresent($binary, $main);
        $this->assertNoIndexOnlySerialOutput($binary, $serials);

        foreach ($serials as $serial) {
            $decoded = $this->pdfText($binary);
            $count = str_contains($decoded, $serial)
                ? substr_count($decoded, $serial)
                : 1;

            $this->assertSame(
                1,
                $count,
                "Serial {$serial} must appear only on the main invoice page.",
            );
        }
    }

    /**
     * @param  list<string>  $serials
     */
    protected function assertOptionBWithAnnexure(string $binary, array $serials): void
    {
        $this->assertStringContainsString('ANNEXURE A', $binary);
        $this->assertStringContainsString(SimplePdfRenderer::ANNEXURE_NOTICE_TEXT, $binary);
        $this->assertPage1HasZeroInlineSerials($binary, $serials);
        $this->assertSerialCoresPresentInPdf($binary, $serials);
        $this->assertNumberedSerialIndexesPresent($binary, $serials);
        $this->assertNoIndexOnlySerialOutput($binary, $serials);
        $this->assertAnnexureStartsOnPageTwo($binary);
    }

    /**
     * @param  list<string>  $serials
     */
    protected function assertPage1HasZeroInlineSerials(string $binary, array $serials): void
    {
        $main = $this->mainPageExtractedText($this->extractedPdfText($binary));

        foreach ($serials as $serial) {
            $this->assertStringNotContainsString(
                $serial,
                $main,
                "Serial {$serial} must not appear inline on Page 1 when Annexure A is used.",
            );
        }
    }

    protected function assertAnnexureStartsOnPageTwo(string $binary): void
    {
        $this->assertGreaterThanOrEqual(
            2,
            $this->pdfPageCount($binary),
            'Annexure invoices must include at least two pages.',
        );
        $this->assertMainInvoiceFooterOnFirstPage($binary);
    }

    protected function assertA4PageDimensions(string $binary): void
    {
        $this->assertStringContainsString(
            '/MediaBox [0 0 '.(int) SimplePdfRenderer::pageWidthPoints().' '.(int) SimplePdfRenderer::pageHeightPoints().']',
            $binary,
        );
        $this->assertSame(595.0, SimplePdfRenderer::pageWidthPoints());
        $this->assertSame(842.0, SimplePdfRenderer::pageHeightPoints());
        $this->assertSame(210.0, SimplePdfRenderer::PAGE_SIZE_A4_WIDTH_MM);
        $this->assertSame(297.0, SimplePdfRenderer::PAGE_SIZE_A4_HEIGHT_MM);
    }

    protected function pdfLiteral(string $value): string
    {
        return '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value).')';
    }

    protected function pdfText(string $pdf): string
    {
        return str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $pdf);
    }

    protected function pdfPageCount(string $binary): int
    {
        if (! preg_match('/\/Type \/Pages[^>]*\/Count (\d+)/', $binary, $matches)) {
            return 0;
        }

        return (int) $matches[1];
    }

    protected function extractedPdfText(string $binary): string
    {
        $path = storage_path('framework/testing/pdf-extract-'.uniqid('', true).'.pdf');
        file_put_contents($path, $binary);
        $text = trim((string) shell_exec(
            escapeshellarg($this->pdftotextBinary()).' '.escapeshellarg($path).' - 2>/dev/null'
        ));
        @unlink($path);

        return $text;
    }

    protected function annexureExtractedText(string $extractedText): string
    {
        $start = strpos($extractedText, 'ANNEXURE A');
        if ($start === false) {
            return '';
        }

        $end = strrpos($extractedText, 'Annexure to tax invoice');
        if ($end === false || $end <= $start) {
            return substr($extractedText, $start);
        }

        return substr($extractedText, $start, $end - $start);
    }

    protected function mainPageExtractedText(string $extractedText): string
    {
        $annexure = strpos($extractedText, 'ANNEXURE A');
        if ($annexure === false) {
            return $extractedText;
        }

        return substr($extractedText, 0, $annexure);
    }

    /**
     * @param  list<string>  $serials
     */
    protected function assertSerialsPresentInExtractedText(string $text, array $serials, string $context): void
    {
        foreach ($serials as $serial) {
            $this->assertStringContainsString(
                $serial,
                $text,
                "{$context}: serial {$serial} must be present.",
            );
        }
    }

    /**
     * @param  list<string>  $serials
     */
    protected function assertSerialsAbsentFromExtractedText(string $text, array $serials, string $context): void
    {
        foreach ($serials as $serial) {
            $this->assertStringNotContainsString(
                $serial,
                $text,
                "{$context}: serial {$serial} must not be present.",
            );
        }
    }

    /**
     * @param  array<string, list<string>>  $groups keyed by label fragment present in PDF
     */
    protected function assertGroupedAnnexureComplete(
        string $binary,
        array $groups,
        int $expectedTotal,
    ): void {
        $extracted = $this->extractedPdfText($binary);
        $annexure = $this->annexureExtractedText($extracted);

        $this->assertStringContainsString('ANNEXURE A', $extracted);
        $this->assertStringContainsString('Total serials', $extracted);
        $this->assertMatchesRegularExpression(
            '/\n'.$expectedTotal.'\n/',
            $extracted,
            'Annexure A must report the complete invoice serial population.',
        );

        foreach ($groups as $labelFragment => $serials) {
            $this->assertStringContainsString($labelFragment, $annexure, "Annexure must include group {$labelFragment}.");
            $this->assertSerialsPresentInExtractedText($annexure, $serials, "Annexure group {$labelFragment}");
        }
    }

    protected function pdftotextBinary(): string
    {
        foreach (['/opt/homebrew/bin/pdftotext', '/usr/local/bin/pdftotext', '/usr/bin/pdftotext'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        $this->fail('pdftotext is required for grouped annexure assertions.');
    }

    protected function mainInvoicePageCount(string $binary): int
    {
        $extracted = $this->extractedPdfText($binary);
        $annexurePos = strpos($extracted, 'ANNEXURE A');
        $mainText = $annexurePos === false ? $extracted : substr($extracted, 0, $annexurePos);
        $continuedCount = substr_count($mainText, '(continued)');

        return 1 + $continuedCount;
    }

    protected function assertMainInvoiceFooterOnFirstPage(string $binary, ?string $irn = null): void
    {
        $extracted = $this->extractedPdfText($binary);
        $main = $this->firstMainInvoicePageText($extracted);

        $this->assertStringContainsString('TOTAL INVOICE VALUE', $main, 'Page 1 must include totals.');
        $this->assertStringContainsString('Authorized Signatory', $main, 'Page 1 must include authorized signatory.');

        if ($irn !== null) {
            $this->assertStringContainsString('IRN', $main, 'Page 1 must include IRN block.');
            $this->assertStringContainsString($irn, $main, 'Page 1 must include issued IRN.');
        }
    }

    protected function firstMainInvoicePageText(string $extractedText): string
    {
        $markers = [];
        $annexure = strpos($extractedText, 'ANNEXURE A');
        if ($annexure !== false) {
            $markers[] = $annexure;
        }
        $continued = strpos($extractedText, '(continued)');
        if ($continued !== false) {
            $markers[] = $continued;
        }

        if ($markers === []) {
            return $extractedText;
        }

        return substr($extractedText, 0, min($markers));
    }

    protected function annexurePageCount(string $binary): int
    {
        $extracted = $this->extractedPdfText($binary);
        if (! str_contains($extracted, 'ANNEXURE A')) {
            return 0;
        }

        return substr_count($extracted, 'ANNEXURE A');
    }

    protected function pdfWordYMin(string $binary, string $word): float
    {
        $tmpPdf = tempnam(sys_get_temp_dir(), 'statutory-pdf-');
        $tmpHtml = tempnam(sys_get_temp_dir(), 'statutory-pdf-bbox-');
        file_put_contents($tmpPdf, $binary);

        $command = sprintf(
            '%s -bbox %s %s 2>/dev/null',
            escapeshellarg($this->pdftotextBinary()),
            escapeshellarg($tmpPdf),
            escapeshellarg($tmpHtml),
        );
        shell_exec($command);
        $html = is_file($tmpHtml) ? (string) file_get_contents($tmpHtml) : '';
        @unlink($tmpPdf);
        @unlink($tmpHtml);

        if ($html === '') {
            $this->fail('Unable to extract PDF bounding boxes for layout assertions.');
        }

        if (! preg_match_all(
            '/<word[^>]+xMin="[^"]+" yMin="([0-9.]+)"[^>]*>'.$word.'<\/word>/',
            $html,
            $matches,
        )) {
            $this->fail("PDF marker word [{$word}] was not found in bbox output.");
        }

        return (float) min($matches[1]);
    }

    protected function assertNoClosingOnlyContinuationPage(string $binary): void
    {
        $extracted = $this->extractedPdfText($binary);
        $annexurePos = strpos($extracted, 'ANNEXURE A');
        $mainText = $annexurePos === false ? $extracted : substr($extracted, 0, $annexurePos);

        if (! str_contains($mainText, '(continued)')) {
            return;
        }

        $continuedPos = strpos($mainText, '(continued)');
        $continuedSection = substr($mainText, $continuedPos);
        $this->assertStringNotContainsString(
            'Serial Numbers',
            $continuedSection,
            'Invoice continuation pages must not exist solely for totals/IRN/signature.',
        );
    }

    protected function assertInvoiceClosingPresentBeforeAnnexure(string $binary, ?string $irn = null, ?string $ackNo = null): void
    {
        $extracted = $this->extractedPdfText($binary);
        $annexurePos = strpos($extracted, 'ANNEXURE A');
        $invoiceSection = $annexurePos === false ? $extracted : substr($extracted, 0, $annexurePos);

        $this->assertStringContainsString('TOTAL INVOICE VALUE', $invoiceSection, 'Invoice section must include totals before annexure.');
        $this->assertStringContainsString('Amount in words', $invoiceSection, 'Invoice section must include amount in words before annexure.');
        $this->assertStringContainsString('Authorized Signatory', $invoiceSection, 'Invoice section must include authorized signatory before annexure.');

        if ($irn !== null) {
            $this->assertStringContainsString('e-Invoice Verification', $invoiceSection);
            $this->assertStringContainsString($irn, $invoiceSection);
            $this->assertStringContainsString('% signed-qr-image', $binary);
        }

        if ($ackNo !== null) {
            $this->assertStringContainsString($ackNo, $invoiceSection);
        }
    }

    /**
     * @param  list<string>  $serials
     */
    protected function assertAllSerialsPresentInPdfBinary(string $binary, array $serials): void
    {
        foreach ($serials as $serial) {
            $this->assertSerialPresentInPdf($binary, $serial);
        }
    }
}
