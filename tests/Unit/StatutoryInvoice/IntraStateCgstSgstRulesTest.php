<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Services\StatutoryInvoice\IntraStateCgstSgstRules;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IntraStateCgstSgstRulesTest extends TestCase
{
    public function test_even_tax_header_half_is_exact(): void
    {
        $this->assertSame(6704, IntraStateCgstSgstRules::headerHalfFromTotalTaxPaise(13408));
        $this->assertSame(0, IntraStateCgstSgstRules::maxInvoiceComponentDriftPaise(13408));
    }

    public function test_odd_paise_tax_header_half_and_drift(): void
    {
        $cases = [
            [9625, 4813],
            [7581, 3791],
            [12051, 6026],
        ];

        foreach ($cases as [$taxPaise, $headerHalf]) {
            $this->assertSame($headerHalf, IntraStateCgstSgstRules::headerHalfFromTotalTaxPaise($taxPaise));
            $this->assertSame(1, IntraStateCgstSgstRules::maxInvoiceComponentDriftPaise($taxPaise));
        }
    }

    public function test_ideal_half_from_taxable_matches_irp_2234_basis(): void
    {
        $this->assertSame(3791, IntraStateCgstSgstRules::idealHalfFromTaxablePaise(42119, 18.0));
        $this->assertSame(763, IntraStateCgstSgstRules::idealHalfFromTaxablePaise(8475, 18.0));
        $this->assertSame(4813, IntraStateCgstSgstRules::idealHalfFromTaxablePaise(53475, 18.0));
    }

    public function test_rd3300_shape_allocates_without_cumulative_drift(): void
    {
        $ideals = [
            IntraStateCgstSgstRules::idealHalfFromTaxablePaise(42119, 18.0),
            IntraStateCgstSgstRules::idealHalfFromTaxablePaise(8475, 18.0),
        ];
        $headerHalf = IntraStateCgstSgstRules::headerHalfFromTotalTaxPaise(9106);
        $allocated = IntraStateCgstSgstRules::allocateLineHalfPaise($headerHalf, $ideals);

        $this->assertSame([3791, 762], $allocated);
        $this->assertSame($headerHalf, array_sum($allocated));
        $this->assertSame(9106, array_sum($allocated) * 2);
    }

    public function test_three_odd_paise_lines_do_not_accumulate_drift(): void
    {
        $taxPaise = [7581, 1525, 9625];
        $taxables = [42119, 8475, 53475];
        $ideals = [];
        foreach ($taxPaise as $index => $tax) {
            $ideals[] = IntraStateCgstSgstRules::idealHalfFromTaxablePaise($taxables[$index], 18.0);
        }

        $totalTaxPaise = array_sum($taxPaise);
        $headerHalf = IntraStateCgstSgstRules::headerHalfFromTotalTaxPaise($totalTaxPaise);
        $allocated = IntraStateCgstSgstRules::allocateLineHalfPaise($headerHalf, $ideals);

        $this->assertSame($headerHalf, array_sum($allocated));
        $componentSum = array_sum($allocated) * 2;
        $this->assertLessThanOrEqual(
            IntraStateCgstSgstRules::maxInvoiceComponentDriftPaise($totalTaxPaise),
            abs($componentSum - $totalTaxPaise),
        );
        $this->assertSame($totalTaxPaise + ($totalTaxPaise % 2), $componentSum);
    }

    public function test_five_odd_paise_lines_do_not_accumulate_drift(): void
    {
        $taxPaise = [7581, 1525, 9625, 12051, 7581];
        $ideals = array_map(
            fn (int $tax): int => IntraStateCgstSgstRules::idealHalfFromTaxablePaise($tax * 100 / 18, 18.0),
            $taxPaise,
        );

        $totalTaxPaise = array_sum($taxPaise);
        $headerHalf = IntraStateCgstSgstRules::headerHalfFromTotalTaxPaise($totalTaxPaise);
        $allocated = IntraStateCgstSgstRules::allocateLineHalfPaise($headerHalf, $ideals);

        $this->assertSame($headerHalf, array_sum($allocated));
        $this->assertSame(
            $totalTaxPaise + IntraStateCgstSgstRules::maxInvoiceComponentDriftPaise($totalTaxPaise),
            array_sum($allocated) * 2,
        );
    }

    public function test_mixed_even_and_odd_lines_reconcile(): void
    {
        $lines = [
            ['taxable' => 42288, 'tax' => 7612],
            ['taxable' => 42119, 'tax' => 7581],
        ];
        $ideals = [];
        $totalTaxPaise = 0;
        foreach ($lines as $line) {
            $ideals[] = IntraStateCgstSgstRules::idealHalfFromTaxablePaise($line['taxable'], 18.0);
            $totalTaxPaise += $line['tax'];
        }

        $headerHalf = IntraStateCgstSgstRules::headerHalfFromTotalTaxPaise($totalTaxPaise);
        $allocated = IntraStateCgstSgstRules::allocateLineHalfPaise($headerHalf, $ideals);

        $this->assertSame($headerHalf, array_sum($allocated));
        $this->assertSame(15193, $totalTaxPaise);
        $this->assertSame(
            $totalTaxPaise + IntraStateCgstSgstRules::maxInvoiceComponentDriftPaise($totalTaxPaise),
            array_sum($allocated) * 2,
        );
    }

    public function test_assert_mint_snapshot_rejects_impossible_allocation(): void
    {
        $this->expectException(ValidationException::class);

        IntraStateCgstSgstRules::assertMintSnapshot(9106, [3791, 763]);
    }

    public function test_money_paise_rounding_is_deterministic(): void
    {
        $this->assertSame(3791, IntraStateCgstSgstRules::moneyPaise(37.91));
        $this->assertSame(37.91, IntraStateCgstSgstRules::fromPaise(3791));
    }
}
