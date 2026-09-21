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
}
