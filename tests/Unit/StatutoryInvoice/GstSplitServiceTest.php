<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Services\StatutoryInvoice\GstSplitService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GstSplitServiceTest extends TestCase
{
    private GstSplitService $split;

    protected function setUp(): void
    {
        parent::setUp();

        $this->split = new GstSplitService;
    }

    public function test_maharashtra_to_maharashtra_18_percent_is_cgst_sgst(): void
    {
        $result = $this->split->splitLine('27', 'Maharashtra', 18.0, 422.88, 76.12);

        $this->assertTrue($result->intraState);
        $this->assertSame(38.06, $result->cgst);
        $this->assertSame(38.06, $result->sgst);
        $this->assertSame(0.0, $result->igst);
        $this->assertSame(9.0, $result->cgstRate);
        $this->assertSame(9.0, $result->sgstRate);
        $this->assertSame(0.0, $result->igstRate);
        $this->assertSame(76.12, round($result->cgst + $result->sgst + $result->igst, 2));
    }

    public function test_delhi_to_delhi_18_percent_is_cgst_sgst(): void
    {
        $result = $this->split->splitLine('07', 'Delhi', 18.0, 422.88, 76.12);

        $this->assertTrue($result->intraState);
        $this->assertSame(38.06, $result->cgst);
        $this->assertSame(38.06, $result->sgst);
        $this->assertSame(0.0, $result->igst);
    }

    public function test_maharashtra_to_karnataka_is_igst(): void
    {
        $result = $this->split->splitLine('27', 'Karnataka', 18.0, 422.88, 76.12);

        $this->assertFalse($result->intraState);
        $this->assertSame(0.0, $result->cgst);
        $this->assertSame(0.0, $result->sgst);
        $this->assertSame(76.12, $result->igst);
        $this->assertSame(18.0, $result->igstRate);
    }

    public function test_delhi_to_maharashtra_is_igst(): void
    {
        $result = $this->split->splitLine('07', 'Maharashtra', 18.0, 422.88, 76.12);

        $this->assertFalse($result->intraState);
        $this->assertSame(0.0, $result->cgst);
        $this->assertSame(0.0, $result->sgst);
        $this->assertSame(76.12, $result->igst);
    }

    public function test_intra_state_rounding_reconciles_to_authoritative_tax(): void
    {
        $result = $this->split->splitLine('27', 'Maharashtra', 18.0, 422.88, 76.12);

        $this->assertSame(76.12, round(422.88 * 0.18, 2));
        $this->assertSame(76.12, round($result->cgst + $result->sgst, 2));
    }

    public function test_missing_place_of_supply_fails_closed(): void
    {
        try {
            $this->split->splitLine('27', null, 18.0, 422.88, 76.12);
            $this->fail('Expected missing place of supply to fail closed.');
        } catch (ValidationException $exception) {
            $this->assertSame([GstSplitService::PLACE_OF_SUPPLY_MISSING], $exception->errors()['gst'] ?? []);
        }
    }

    public function test_unrecognised_place_of_supply_fails_closed(): void
    {
        try {
            $this->split->splitLine('27', 'Narnia', 18.0, 422.88, 76.12);
            $this->fail('Expected unrecognised place of supply to fail closed.');
        } catch (ValidationException $exception) {
            $this->assertSame([GstSplitService::PLACE_OF_SUPPLY_UNRECOGNISED], $exception->errors()['gst'] ?? []);
        }
    }

    public function test_missing_gst_rate_fails_closed(): void
    {
        try {
            $this->split->splitLine('27', 'Maharashtra', null, 422.88, 76.12);
            $this->fail('Expected missing GST rate to fail closed.');
        } catch (ValidationException $exception) {
            $this->assertSame([GstSplitService::GST_RATE_INVALID], $exception->errors()['gst'] ?? []);
        }
    }

    public function test_zero_gst_rate_with_tax_fails_closed(): void
    {
        try {
            $this->split->splitLine('27', 'Maharashtra', 0.0, 422.88, 76.12);
            $this->fail('Expected invalid GST rate to fail closed.');
        } catch (ValidationException $exception) {
            $this->assertSame([GstSplitService::GST_RATE_INVALID], $exception->errors()['gst'] ?? []);
        }
    }

    public function test_tax_component_mismatch_fails_closed(): void
    {
        try {
            $this->split->splitLine('27', 'Maharashtra', 18.0, 422.88, 10.00);
            $this->fail('Expected tax mismatch to fail closed.');
        } catch (ValidationException $exception) {
            $this->assertSame([GstSplitService::TAX_MISMATCH], $exception->errors()['gst'] ?? []);
        }
    }

    public function test_zero_value_line_keeps_applicable_zero_components(): void
    {
        $result = $this->split->splitLine('27', 'Maharashtra', 18.0, 0.0, 0.0);

        $this->assertTrue($result->intraState);
        $this->assertSame(0.0, $result->cgst);
        $this->assertSame(0.0, $result->sgst);
        $this->assertSame(0.0, $result->igst);
        $this->assertSame(9.0, $result->cgstRate);
    }
}
