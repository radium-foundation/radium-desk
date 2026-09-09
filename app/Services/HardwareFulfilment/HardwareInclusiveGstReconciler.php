<?php

namespace App\Services\HardwareFulfilment;

use App\Models\CommerceOrderItem;
use App\Services\HardwareFulfilment\Data\HardwareInclusiveGstAmounts;
use App\Services\StatutoryInvoice\GstSplitService;
use Illuminate\Validation\ValidationException;

/**
 * Projects invoice GST from an inclusive hardware line without rewriting commerce.
 *
 * Gross line_total is authoritative for the invoice total. Exact stored
 * exclusive+inclusive identity is kept. Harmless one-paisa rounding projects:
 * taxable = round(gross / (1 + rate/100), 2) and GST = gross − taxable.
 * Material mismatches fail closed.
 */
final class HardwareInclusiveGstReconciler
{
    public const PAISA_TOLERANCE = 1;

    public const MISSING_RATE = 'Hardware invoice issuance requires a GST rate, or taxable value and tax that reconcile to a GST rate.';

    public const CONFLICTING_RATE = 'Hardware invoice issuance found conflicting GST rates.';

    /**
     * @var list<float>
     */
    public const LEGAL_RATES = [0.0, 5.0, 12.0, 18.0, 28.0];

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
        $explicit = $this->explicitRate($item);
        $derived = $this->derivedLegalRate($storedTaxable, $storedTax);

        if ($explicit !== null && $derived !== null && $explicit !== $derived) {
            throw ValidationException::withMessages([
                'gst' => self::CONFLICTING_RATE,
            ]);
        }

        if ($explicit !== null) {
            return $explicit;
        }

        if ($derived !== null) {
            return $derived;
        }

        throw ValidationException::withMessages([
            'gst' => $item->gst_percentage !== null && $item->gst_percentage !== ''
                ? GstSplitService::GST_RATE_INVALID
                : self::MISSING_RATE,
        ]);
    }

    private function explicitRate(CommerceOrderItem $item): ?float
    {
        if ($item->gst_percentage === null || $item->gst_percentage === '') {
            return null;
        }

        $rate = $this->money((float) $item->gst_percentage);
        if ($rate === 0.0 && $item->tax_total !== null && $this->money((float) $item->tax_total) > 0.0) {
            throw ValidationException::withMessages([
                'gst' => GstSplitService::GST_RATE_INVALID,
            ]);
        }

        $legal = $this->legalRate($rate);
        if ($legal === null) {
            throw ValidationException::withMessages([
                'gst' => GstSplitService::GST_RATE_INVALID,
            ]);
        }

        return $legal;
    }

    private function derivedLegalRate(?float $storedTaxable, ?float $storedTax): ?float
    {
        if ($storedTaxable === null || $storedTax === null || $storedTaxable <= 0.0) {
            return null;
        }

        return $this->legalRate(round(($storedTax / $storedTaxable) * 100, 2));
    }

    private function legalRate(float $rate): ?float
    {
        $rate = $this->money($rate);
        foreach (self::LEGAL_RATES as $legal) {
            if ($rate === $legal) {
                return $legal;
            }
        }

        return null;
    }

    private function fromInclusiveGross(float $gross, float $rate): HardwareInclusiveGstAmounts
    {
        if ($rate <= 0.0) {
            throw ValidationException::withMessages([
                'gst' => GstSplitService::GST_RATE_INVALID,
            ]);
        }

        $grossPaise = $this->paise($gross);
        $taxablePaise = $this->paise($this->money($gross / (1 + ($rate / 100))));
        $taxPaise = $grossPaise - $taxablePaise;
        $taxable = $this->fromPaise($taxablePaise);
        $tax = $this->fromPaise($taxPaise);

        if (($taxablePaise + $taxPaise) !== $grossPaise || $taxPaise < 0) {
            throw ValidationException::withMessages([
                'gst' => GstSplitService::TAX_MISMATCH,
            ]);
        }

        $exclusiveExpectedPaise = $this->paise($this->money($taxable * ($rate / 100)));
        if (abs($exclusiveExpectedPaise - $taxPaise) > self::PAISA_TOLERANCE) {
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

    private function fromPaise(int $paise): float
    {
        return round($paise / 100, 2);
    }
}
