<?php

namespace App\Support\StatutoryInvoice;

/**
 * Indian invoice header round-off: nearest rupee, GST/tax unchanged.
 * PHP round() is half away from zero for positive amounts (.50 → up).
 */
final class InvoiceRoundOff
{
    /**
     * @return array{unrounded: float, rounding: float, rounded: float}
     */
    public static function nearestRupee(float $amount): array
    {
        $unrounded = round($amount, 2);
        $rounded = round($unrounded, 0);
        $rounding = round($rounded - $unrounded, 2);

        return [
            'unrounded' => $unrounded,
            'rounding' => $rounding,
            'rounded' => round($rounded, 2),
        ];
    }
}
