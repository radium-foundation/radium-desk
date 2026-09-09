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
                'gst_percentage' => null,
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
