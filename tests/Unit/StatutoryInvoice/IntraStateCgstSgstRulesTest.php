<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Services\StatutoryInvoice\IntraStateCgstSgstRules;
use Tests\TestCase;

class IntraStateCgstSgstRulesTest extends TestCase
{
    public function test_even_tax_split_is_equal_and_exact(): void
    {
        [$cgst, $sgst] = IntraStateCgstSgstRules::equalHalves(134.08);

        $this->assertSame(67.04, $cgst);
        $this->assertSame(67.04, $sgst);
        $this->assertSame(0, IntraStateCgstSgstRules::componentSumDriftPaise(134.08));
    }

    public function test_odd_paise_tax_uses_equal_halves_with_one_paisa_drift(): void
    {
        $cases = [
            [96.25, 48.13],
            [75.81, 37.91],
            [120.51, 60.26],
        ];

        foreach ($cases as [$tax, $half]) {
            [$cgst, $sgst] = IntraStateCgstSgstRules::equalHalves($tax);

            $this->assertSame($half, $cgst);
            $this->assertSame($half, $sgst);
            $this->assertSame(1, IntraStateCgstSgstRules::componentSumDriftPaise($tax));
        }
    }
}
