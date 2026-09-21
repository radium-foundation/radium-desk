<?php

namespace Tests\Unit\Inventory;

use App\Support\Inventory\PosRetailShippingGst;
use PHPUnit\Framework\TestCase;

class PosRetailShippingGstTest extends TestCase
{
    public function test_max_line_gst_rate_uses_highest_rate(): void
    {
        $this->assertSame(18.0, PosRetailShippingGst::maxLineGstRate([5.0, 12.0, 18.0]));
    }

    public function test_tax_on_exclusive_amount_matches_pos_line_formula(): void
    {
        $this->assertSame(9.0, PosRetailShippingGst::taxOnExclusiveAmount(50.0, 18.0));
        $this->assertSame(0.0, PosRetailShippingGst::taxOnExclusiveAmount(0.0, 18.0));
    }
}
