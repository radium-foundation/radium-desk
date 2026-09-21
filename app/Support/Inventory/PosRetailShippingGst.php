<?php

namespace App\Support\Inventory;

/**
 * POS retail Hardware customer shipping uses the same exclusive GST formula as product
 * lines (taxable × rate / 100). This is Desk POS architecture — not Admin 18% reverse GST.
 */
final class PosRetailShippingGst
{
    /**
     * @param  list<float>  $lineGstRates
     */
    public static function maxLineGstRate(array $lineGstRates): float
    {
        if ($lineGstRates === []) {
            return 0.0;
        }

        $max = 0.0;
        foreach ($lineGstRates as $rate) {
            $max = max($max, max(0.0, (float) $rate));
        }

        return $max;
    }

    public static function taxOnExclusiveAmount(float $shippingAmount, float $gstRate): float
    {
        $shippingAmount = round(max(0.0, $shippingAmount), 2);
        if ($shippingAmount <= 0 || $gstRate <= 0) {
            return 0.0;
        }

        return round($shippingAmount * ($gstRate / 100), 2);
    }
}
