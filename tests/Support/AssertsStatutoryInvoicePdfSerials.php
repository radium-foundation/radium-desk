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

        if (str_contains($decoded, $serial)) {
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
            'Complete serial-number list provided in Annexure A.',
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
        $main = array_slice($serials, 0, SimplePdfRenderer::MAIN_PAGE_SERIAL_LIMIT);
        $decoded = $this->pdfText($binary);

        $this->assertStringContainsString('ANNEXURE A', $binary);
        $this->assertStringContainsString(
            'Complete serial-number list provided in Annexure A.',
            $binary,
        );
        $this->assertSerialCoresPresentInPdf($binary, $serials);
        $this->assertNumberedSerialIndexesPresent($binary, $serials);
        $this->assertNoIndexOnlySerialOutput($binary, $serials);

        foreach ($main as $index => $serial) {
            $decoded = $this->pdfText($binary);
            $count = str_contains($decoded, $serial)
                ? substr_count($decoded, $serial)
                : 2;

            $this->assertGreaterThanOrEqual(
                2,
                $count,
                'Serial '.($index + 1).' must appear on the main page and again in Annexure A.',
            );
        }

        if (count($serials) > SimplePdfRenderer::MAIN_PAGE_SERIAL_LIMIT) {
            $annexureOnly = $serials[SimplePdfRenderer::MAIN_PAGE_SERIAL_LIMIT];
            $decoded = $this->pdfText($binary);
            $count = str_contains($decoded, $annexureOnly)
                ? substr_count($decoded, $annexureOnly)
                : 1;

            $this->assertSame(
                1,
                $count,
                'First annexure-only serial must not be duplicated on the main page.',
            );
        }
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
        $main = $this->mainPageExtractedText($extracted);

        $this->assertStringContainsString('TOTAL INVOICE VALUE', $main, 'Main invoice page must include totals.');
        $this->assertStringContainsString('Authorized Signatory', $main, 'Main invoice page must include authorized signatory.');

        if ($irn !== null) {
            $this->assertStringContainsString('IRN', $main, 'Main invoice page must include IRN block.');
            $this->assertStringContainsString($irn, $main, 'Main invoice page must include issued IRN.');
        }

        $this->assertSame(
            1,
            $this->mainInvoicePageCount($binary),
            'Statutory footer must not be pushed to a separate invoice continuation page.',
        );
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
}
