<?php

namespace App\Services\HardwareFulfilment;

use App\Models\CommerceOrderItem;
use App\Services\HardwareFulfilment\Data\HardwareInclusiveGstAmounts;
use App\Services\StatutoryInvoice\GstSplitService;
use Illuminate\Validation\ValidationException;

/**
 * Projects invoice GST from an inclusive hardware line without rewriting commerce.
 *
 * Stored taxable/tax may miss exclusive identity by one paisa after Box
 * scales a 3-decimal unit tax. Invoice values are then taken from gross:
 * taxable = round(gross / (1 + rate/100), 2) and tax = round(taxable × rate/100, 2).
 * Larger mismatches fail closed.
 */
final class HardwareInclusiveGstReconciler
{
    public const PAISA_TOLERANCE = 1;

    public const MISSING_RATE = 'Hardware invoice issuance requires a GST rate, or taxable value and tax that reconcile to a GST rate.';

    public function reconcile(CommerceOrderItem $item): HardwareInclusiveGstAmounts
    {
        $gross = $this->money((float) $item->line_total);
        $storedTaxable = $item->taxable_value !== null ? $this->money((float) $item->taxable_value) : null;
        $storedTax = $item->tax_total !== null ? $this->money((float) $item->tax_total) : null;
        $rate = $this->resolveRate($item, $storedTaxable, $storedTax);

        if ($gross <= 0.0 || $storedTaxable === null || $storedTax === null || $storedTaxable < 0.0 || $storedTax < 0.0) {
            throw ValidationException::withMessages([
                'gst' => self::MISSING_RATE,
            ]);
        }

        $exclusiveExpected = $this->money($storedTaxable * ($rate / 100));
        $exclusiveDelta = abs($this->paise($exclusiveExpected) - $this->paise($storedTax));
        $inclusiveDelta = abs(($this->paise($storedTaxable) + $this->paise($storedTax)) - $this->paise($gross));

        if ($exclusiveDelta === 0 && $inclusiveDelta === 0) {
            return new HardwareInclusiveGstAmounts(
                gstPercentage: $rate,
                taxableValue: $storedTaxable,
                taxTotal: $storedTax,
                lineTotal: $gross,
            );
        }

        if ($exclusiveDelta > self::PAISA_TOLERANCE || $inclusiveDelta > self::PAISA_TOLERANCE) {
            throw ValidationException::withMessages([
                'gst' => GstSplitService::TAX_MISMATCH,
            ]);
        }

        return $this->fromInclusiveGross($gross, $rate);
    }

    private function resolveRate(CommerceOrderItem $item, ?float $storedTaxable, ?float $storedTax): float
    {
        if ($item->gst_percentage !== null && $item->gst_percentage !== '') {
            $rate = $this->money((float) $item->gst_percentage);
            if ($rate < 0.0 || $rate > 100.0) {
                throw ValidationException::withMessages([
                    'gst' => GstSplitService::GST_RATE_INVALID,
                ]);
            }
            if ($rate === 0.0 && $storedTax !== null && $storedTax > 0.0) {
                throw ValidationException::withMessages([
                    'gst' => GstSplitService::GST_RATE_INVALID,
                ]);
            }

            return $rate;
        }

        if ($storedTaxable === null || $storedTax === null || $storedTaxable <= 0.0) {
            throw ValidationException::withMessages([
                'gst' => self::MISSING_RATE,
            ]);
        }

        $rate = round(($storedTax / $storedTaxable) * 100, 2);
        if ($rate < 0.0 || $rate > 100.0) {
            throw ValidationException::withMessages([
                'gst' => GstSplitService::GST_RATE_INVALID,
            ]);
        }

        return $rate;
    }

    private function fromInclusiveGross(float $gross, float $rate): HardwareInclusiveGstAmounts
    {
        if ($rate <= 0.0) {
            throw ValidationException::withMessages([
                'gst' => GstSplitService::GST_RATE_INVALID,
            ]);
        }

        $taxable = $this->money($gross / (1 + ($rate / 100)));
        $tax = $this->money($taxable * ($rate / 100));

        if (($this->paise($taxable) + $this->paise($tax)) !== $this->paise($gross)) {
            throw ValidationException::withMessages([
                'gst' => GstSplitService::TAX_MISMATCH,
            ]);
        }

        if ($this->paise($this->money($taxable * ($rate / 100))) !== $this->paise($tax)) {
            throw ValidationException::withMessages([
                'gst' => GstSplitService::TAX_MISMATCH,
            ]);
        }

        return new HardwareInclusiveGstAmounts(
            gstPercentage: $rate,
            taxableValue: $taxable,
            taxTotal: $tax,
            lineTotal: $gross,
        );
    }

    private function money(float $amount): float
    {
        return round($amount, 2);
    }

    private function paise(float $amount): int
    {
        return (int) round($this->money($amount) * 100);
    }
}
