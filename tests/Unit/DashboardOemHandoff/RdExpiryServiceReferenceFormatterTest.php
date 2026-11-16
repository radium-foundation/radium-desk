<?php

namespace Tests\Unit\DashboardOemHandoff;

use App\Services\DashboardOemHandoff\RdExpiryServiceReferenceFormatter;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RdExpiryServiceReferenceFormatterTest extends TestCase
{
    private RdExpiryServiceReferenceFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new RdExpiryServiceReferenceFormatter();
    }

    public function test_formats_owner_example(): void
    {
        $this->assertSame(
            'AW-20-01-27-01',
            $this->formatter->formatFromIsoDate('2027-01-20', 1),
        );
    }

  #[DataProvider('leapYearDatesProvider')]
    public function test_formats_leap_and_year_boundaries(string $iso, string $expectedPrefix): void
    {
        $reference = $this->formatter->formatFromIsoDate($iso, 3);
        $this->assertStringStartsWith($expectedPrefix, $reference);
        $this->assertSame($expectedPrefix.'03', $reference);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function leapYearDatesProvider(): array
    {
        return [
            ['2024-02-29', 'AW-29-02-24-'],
            ['2025-12-31', 'AW-31-12-25-'],
            ['2026-01-01', 'AW-01-01-26-'],
        ];
    }

    public function test_parse_canonical_reference(): void
    {
        $parsed = $this->formatter->parseCanonicalReference('AW-20-01-27-02');
        $this->assertNotNull($parsed);
        $this->assertSame('02', $parsed['nn']);
    }

    public function test_rejects_legacy_non_canonical_reference(): void
    {
        $this->assertNull($this->formatter->parseCanonicalReference('AW-RD3444738'));
    }

    public function test_format_uses_two_digit_sequence_padding(): void
    {
        $ref = $this->formatter->format(CarbonImmutable::parse('2027-01-20'), 12);
        $this->assertSame('AW-20-01-27-12', $ref);
    }
}