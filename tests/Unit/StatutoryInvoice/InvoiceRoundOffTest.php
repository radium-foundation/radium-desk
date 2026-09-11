<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Support\StatutoryInvoice\InvoiceRoundOff;
use Tests\TestCase;

class InvoiceRoundOffTest extends TestCase
{
    public function test_nearest_rupee_boundaries(): void
    {
        $cases = [
            [11249.94, 0.06, 11250.00],
            [11249.49, -0.49, 11249.00],
            [11249.50, 0.50, 11250.00],
            [11249.01, -0.01, 11249.00],
            [11249.99, 0.01, 11250.00],
            [118.00, 0.00, 118.00],
        ];

        foreach ($cases as [$amount, $rounding, $rounded]) {
            $result = InvoiceRoundOff::nearestRupee($amount);
            $this->assertEqualsWithDelta($amount, $result['unrounded'], 0.001);
            $this->assertEqualsWithDelta($rounding, $result['rounding'], 0.001);
            $this->assertEqualsWithDelta($rounded, $result['rounded'], 0.001);
        }
    }
}
