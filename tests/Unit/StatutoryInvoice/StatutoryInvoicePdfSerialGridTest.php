<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Services\StatutoryInvoice\SimplePdfRenderer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class StatutoryInvoicePdfSerialGridTest extends TestCase
{
    public function test_numbered_serial_grid_preserves_production_length_serials_for_one_hundred_twenty_items(): void
    {
        $serials = [];
        for ($i = 1; $i <= 120; $i++) {
            $serials[] = sprintf(
                'H%05d-MP826D81704%04d-09/26',
                22000 + $i - 1,
                2835 + ($i * 17) % 10000,
            );
        }

        $ops = [];
        $rowY = $this->renderGrid($ops, $serials, 1, 754.0);

        $stream = implode('', $ops);
        foreach ($serials as $index => $serial) {
            $core = substr($serial, 0, -5);
            $this->assertStringContainsString(
                $this->pdfLiteral($core),
                $stream,
                'Serial #'.($index + 1).' core must be written into the PDF stream.',
            );
        }

        $this->assertSame(120, substr_count($stream, $this->pdfLiteral('09/26')));
    }

    public function test_numbered_serial_grid_writes_index_and_serial_separately(): void
    {
        $serial = 'H22100-MP826D817042835-09/26';
        $ops = [];
        $this->renderGrid($ops, [$serial], 109, 754.0);
        $stream = implode('', $ops);

        $this->assertStringContainsString($this->pdfLiteral('109.'), $stream);
        $this->assertStringContainsString($this->pdfLiteral('H22100-MP826D817042835-'), $stream);
        $this->assertStringContainsString($this->pdfLiteral('09/26'), $stream);
    }

    /**
     * @param  list<string>  $ops
     * @param  list<string>  $serials
     */
    private function renderGrid(array &$ops, array $serials, int $startNumber, float $y): float
    {
        $renderer = new SimplePdfRenderer;
        $method = new ReflectionMethod(SimplePdfRenderer::class, 'renderNumberedSerialGrid');
        $method->setAccessible(true);
        $args = [&$ops, $serials, $startNumber, $y, true];
        [$rowY] = $method->invokeArgs($renderer, $args);

        return $rowY;
    }

    private function pdfLiteral(string $value): string
    {
        return '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value).')';
    }
}
