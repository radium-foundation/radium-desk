<?php

namespace Tests\Unit;

use App\Support\DeviceModelFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeviceModelFormatterTest extends TestCase
{
    #[DataProvider('shortDisplayExamples')]
    public function test_short_display_formats_full_model(string $fullModel, string $expected): void
    {
        $this->assertSame($expected, DeviceModelFormatter::shortDisplay($fullModel));
    }

    public static function shortDisplayExamples(): array
    {
        return [
            'MIS combined with marketing suffix' => ['MIS100 IRIS', 'MIS 100'],
            'MIS already spaced' => ['MIS 100', 'MIS 100'],
            'Access FM with suffix' => ['Access FM 220 U', 'FM 220'],
            'Access FM combined from RadiumBox' => ['Access FM220U L1', 'FM 220'],
            'MSO with variant and install suffixes' => ['MSO 1300 E3 RD L1', 'MSO E3'],
            'MFS combined token' => ['MFS110', 'MFS 110'],
            'MFS with description' => ['MFS 110 Refrigerator...', 'MFS 110'],
            'FM with description' => ['FM 220 Water Purifier', 'FM 220'],
            'two token non numeric' => ['MFS TAB', 'MFS TAB'],
            'morpho with number' => ['Morpho 1300', 'Morpho 1300'],
            'morpho with variant' => ['Morpho 1300 E3 RD L1', 'Morpho E3'],
            'standalone install code preserved' => ['L1', 'L1'],
        ];
    }

    public function test_short_display_returns_null_for_empty_input(): void
    {
        $this->assertNull(DeviceModelFormatter::shortDisplay(null));
        $this->assertNull(DeviceModelFormatter::shortDisplay(''));
        $this->assertNull(DeviceModelFormatter::shortDisplay('   '));
    }
}
