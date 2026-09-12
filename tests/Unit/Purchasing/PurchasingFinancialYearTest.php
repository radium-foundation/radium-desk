<?php

namespace Tests\Unit\Purchasing;

use App\Support\Purchasing\PurchasingFinancialYear;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PurchasingFinancialYearTest extends TestCase
{
    #[DataProvider('financialYearProvider')]
    public function test_series_code_for_indian_financial_year(string $date, string $expectedSeries, string $expectedPrefix): void
    {
        $fy = PurchasingFinancialYear::containing(Carbon::parse($date));

        $this->assertSame($expectedSeries, $fy->seriesCode());
        $this->assertSame($expectedPrefix, $fy->purchaseOrderPrefix());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function financialYearProvider(): array
    {
        return [
            'fy 2026-27 before april' => ['2026-03-31', '06', 'PO-06-'],
            'fy 2026-27 start' => ['2026-04-01', '07', 'PO-07-'],
            'fy 2026-27 mid' => ['2026-09-12', '07', 'PO-07-'],
            'fy 2027-28 start' => ['2027-04-01', '08', 'PO-08-'],
        ];
    }
}
