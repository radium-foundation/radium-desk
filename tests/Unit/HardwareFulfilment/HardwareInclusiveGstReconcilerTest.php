<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Models\CommerceOrderItem;
use App\Services\HardwareFulfilment\HardwareInclusiveGstReconciler;
use App\Services\StatutoryInvoice\GstSplitService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HardwareInclusiveGstReconcilerTest extends TestCase
{
    private HardwareInclusiveGstReconciler $reconciler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reconciler = new HardwareInclusiveGstReconciler;
    }

    public function test_qty_one_inclusive_2499_keeps_stored_taxable_and_gst(): void
    {
        $result = $this->reconciler->reconcile($this->item([
            'gst_percentage' => null,
            'taxable_value' => 2117.80,
            'tax_total' => 381.20,
            'line_total' => 2499.00,
        ]));

        $this->assertSame(18.0, $result->gstPercentage);
        $this->assertSame(2117.80, $result->taxableValue);
        $this->assertSame(381.20, $result->taxTotal);
        $this->assertSame(2499.00, $result->lineTotal);
        $this->assertSame(249900, (int) round($result->taxableValue * 100) + (int) round($result->taxTotal * 100));
    }

    public function test_qty_ten_one_paisa_inclusive_projects_from_gross(): void
    {
        $result = $this->reconciler->reconcile($this->item([
            'gst_percentage' => null,
            'taxable_value' => 21177.96,
            'tax_total' => 3812.04,
            'line_total' => 24990.00,
        ]));

        $this->assertSame(18.0, $result->gstPercentage);
        $this->assertSame(21177.97, $result->taxableValue);
        $this->assertSame(3812.03, $result->taxTotal);
        $this->assertSame(24990.00, $result->lineTotal);
        $this->assertSame(2499000, (int) round($result->taxableValue * 100) + (int) round($result->taxTotal * 100));
        $this->assertSame(3812.03, round($result->taxableValue * 0.18, 2));
    }

    public function test_qty_five_inclusive_12495_keeps_stored_amounts(): void
    {
        $result = $this->reconciler->reconcile($this->item([
            'gst_percentage' => null,
            'taxable_value' => 10588.98,
            'tax_total' => 1906.02,
            'line_total' => 12495.00,
        ]));

        $this->assertSame(18.0, $result->gstPercentage);
        $this->assertSame(10588.98, $result->taxableValue);
        $this->assertSame(1906.02, $result->taxTotal);
        $this->assertSame(12495.00, $result->lineTotal);
        $this->assertSame(1249500, (int) round($result->taxableValue * 100) + (int) round($result->taxTotal * 100));
    }

    public function test_rde318435_qty_one_3849_projects_gst_as_gross_minus_taxable(): void
    {
        $result = $this->reconciler->reconcile($this->item([
            'gst_percentage' => null,
            'taxable_value' => 3261.87,
            'tax_total' => 587.13,
            'line_total' => 3849.00,
        ]));

        $this->assertSame(18.0, $result->gstPercentage);
        $this->assertSame(3261.86, $result->taxableValue);
        $this->assertSame(587.14, $result->taxTotal);
        $this->assertSame(3849.00, $result->lineTotal);
        $this->assertSame(384900, (int) round($result->taxableValue * 100) + (int) round($result->taxTotal * 100));
        $this->assertSame(587.13, round($result->taxableValue * 0.18, 2));
    }

    public function test_rde318401_qty_one_2999_projects_gst_as_gross_minus_taxable(): void
    {
        $result = $this->reconciler->reconcile($this->item([
            'gst_percentage' => null,
            'taxable_value' => 2541.52,
            'tax_total' => 457.48,
            'line_total' => 2999.00,
        ]));

        $this->assertSame(18.0, $result->gstPercentage);
        $this->assertSame(2541.53, $result->taxableValue);
        $this->assertSame(457.47, $result->taxTotal);
        $this->assertSame(2999.00, $result->lineTotal);
        $this->assertSame(299900, (int) round($result->taxableValue * 100) + (int) round($result->taxTotal * 100));
    }

    public function test_rin_qty_one_2649_keeps_gross_residual_with_explicit_rate(): void
    {
        $result = $this->reconciler->reconcile($this->item([
            'gst_percentage' => 18,
            'taxable_value' => 2244.92,
            'tax_total' => 404.08,
            'line_total' => 2649.00,
        ]));

        $this->assertSame(18.0, $result->gstPercentage);
        $this->assertSame(2244.92, $result->taxableValue);
        $this->assertSame(404.08, $result->taxTotal);
        $this->assertSame(2649.00, $result->lineTotal);
        $this->assertSame(264900, (int) round($result->taxableValue * 100) + (int) round($result->taxTotal * 100));
        $this->assertSame(404.09, round($result->taxableValue * 0.18, 2));
    }

    public function test_unit_multiplication_does_not_override_line_gross(): void
    {
        $result = $this->reconciler->reconcile($this->item([
            'gst_percentage' => null,
            'taxable_value' => 21177.96,
            'tax_total' => 3812.04,
            'line_total' => 24990.00,
        ]));

        $this->assertNotSame(3812.04, $result->taxTotal);
        $this->assertSame(3812.03, $result->taxTotal);
        $this->assertSame(24990.00, round($result->taxableValue + $result->taxTotal, 2));
    }

    public function test_conflicting_explicit_and_derived_legal_rates_fail_closed(): void
    {
        try {
            $this->reconciler->reconcile($this->item([
                'gst_percentage' => 12,
                'taxable_value' => 2117.80,
                'tax_total' => 381.20,
                'line_total' => 2499.00,
            ]));
            $this->fail('Expected conflicting GST rates to fail closed.');
        } catch (ValidationException $exception) {
            $this->assertSame([HardwareInclusiveGstReconciler::CONFLICTING_RATE], $exception->errors()['gst'] ?? []);
        }
    }

    public function test_derived_rate_that_is_not_a_legal_slab_fails_closed(): void
    {
        try {
            $this->reconciler->reconcile($this->item([
                'gst_percentage' => null,
                'taxable_value' => 1000.00,
                'tax_total' => 333.33,
                'line_total' => 1333.33,
            ]));
            $this->fail('Expected a non-legal derived GST rate to fail closed.');
        } catch (ValidationException $exception) {
            $this->assertSame([HardwareInclusiveGstReconciler::MISSING_RATE], $exception->errors()['gst'] ?? []);
        }
    }

    public function test_mismatch_larger_than_one_paisa_fails_closed(): void
    {
        try {
            $this->reconciler->reconcile($this->item([
                'gst_percentage' => 18,
                'taxable_value' => 21177.96,
                'tax_total' => 3812.00,
                'line_total' => 24990.00,
            ]));
            $this->fail('Expected a GST mismatch larger than one paisa to fail closed.');
        } catch (ValidationException $exception) {
            $this->assertSame([GstSplitService::TAX_MISMATCH], $exception->errors()['gst'] ?? []);
        }
    }

    public function test_missing_taxable_and_rate_fails_closed(): void
    {
        try {
            $this->reconciler->reconcile($this->item([
                'gst_percentage' => null,
                'taxable_value' => null,
                'tax_total' => 3812.04,
                'line_total' => 24990.00,
            ]));
            $this->fail('Expected missing tax data to fail closed.');
        } catch (ValidationException $exception) {
            $this->assertSame([HardwareInclusiveGstReconciler::MISSING_RATE], $exception->errors()['gst'] ?? []);
        }
    }

    public function test_unreconciled_tax_total_rewrite_is_not_accepted(): void
    {
        try {
            $this->reconciler->reconcile($this->item([
                'gst_percentage' => 18,
                'taxable_value' => 2583.90,
                'tax_total' => 50.00,
                'line_total' => 3049.00,
            ]));
            $this->fail('Expected an unreconciled GST lump to fail closed.');
        } catch (ValidationException $exception) {
            $this->assertSame([GstSplitService::TAX_MISMATCH], $exception->errors()['gst'] ?? []);
        }
    }

    /**
     * @param  array{gst_percentage: mixed, taxable_value: mixed, tax_total: mixed, line_total: float}  $attributes
     */
    private function item(array $attributes): CommerceOrderItem
    {
        $item = new CommerceOrderItem;
        $item->forceFill($attributes);

        return $item;
    }
}
