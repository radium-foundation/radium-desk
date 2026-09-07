<?php

namespace App\Services\StatutoryInvoice;

use App\Services\StatutoryInvoice\Data\GstComponentSplit;
use App\Support\Finance\GstStateCodes;
use Illuminate\Validation\ValidationException;

/**
 * Decomposes an authoritative service GST lump into CGST/SGST or IGST.
 *
 * Intra-State vs inter-State follows IGST Act ss.7–8: seller GST state versus
 * place of supply. billing_state is not used. The commerce tax_total is never
 * rewritten; a mismatch fails closed.
 */
final class GstSplitService
{
    public const PLACE_OF_SUPPLY_MISSING = 'Place of supply is missing.';

    public const PLACE_OF_SUPPLY_UNRECOGNISED = 'Place of supply is not a recognised Indian GST state.';

    public const SELLER_STATE_UNSET = 'Seller GST state cannot be determined.';

    public const GST_RATE_INVALID = 'GST rate is missing or invalid.';

    public const TAXABLE_INVALID = 'Taxable amount is invalid.';

    public const TAX_MISMATCH = 'GST amount does not match taxable value × rate.';

    public const COMPONENTS_MISMATCH = 'GST components do not reconcile to total GST.';

    public function splitLine(
        string $sellerGstStateCode,
        ?string $placeOfSupplyState,
        ?float $gstPercentage,
        float $taxableValue,
        float $taxTotal,
    ): GstComponentSplit {
        if (! GstStateCodes::isKnownCode($sellerGstStateCode)) {
            throw ValidationException::withMessages([
                'gst' => self::SELLER_STATE_UNSET,
            ]);
        }

        $place = is_string($placeOfSupplyState) ? trim($placeOfSupplyState) : '';
        if ($place === '') {
            throw ValidationException::withMessages([
                'gst' => self::PLACE_OF_SUPPLY_MISSING,
            ]);
        }

        $placeCode = GstStateCodes::codeForName($place);
        if ($placeCode === null) {
            throw ValidationException::withMessages([
                'gst' => self::PLACE_OF_SUPPLY_UNRECOGNISED,
            ]);
        }

        if (! is_finite($taxableValue) || ! is_finite($taxTotal) || $taxableValue < 0 || $taxTotal < 0) {
            throw ValidationException::withMessages([
                'gst' => self::TAXABLE_INVALID,
            ]);
        }

        if ($gstPercentage === null || ! is_finite($gstPercentage) || $gstPercentage < 0 || $gstPercentage > 100) {
            throw ValidationException::withMessages([
                'gst' => self::GST_RATE_INVALID,
            ]);
        }

        $taxable = $this->money($taxableValue);
        $tax = $this->money($taxTotal);

        if ($gstPercentage === 0.0 && $tax > 0) {
            throw ValidationException::withMessages([
                'gst' => self::GST_RATE_INVALID,
            ]);
        }

        if ($gstPercentage > 0 || $taxable > 0) {
            $expected = $this->money($taxable * ($gstPercentage / 100));
            if ($expected !== $tax) {
                throw ValidationException::withMessages([
                    'gst' => self::TAX_MISMATCH,
                ]);
            }
        }

        $intraState = trim($sellerGstStateCode) === $placeCode;

        if ($intraState) {
            $cgst = $this->money($tax / 2);
            $sgst = $this->money($tax - $cgst);
            $igst = 0.0;
            $halfRate = $gstPercentage / 2;
            $split = new GstComponentSplit(
                cgst: $cgst,
                sgst: $sgst,
                igst: $igst,
                cgstRate: $halfRate,
                sgstRate: $halfRate,
                igstRate: 0.0,
                intraState: true,
            );
        } else {
            $split = new GstComponentSplit(
                cgst: 0.0,
                sgst: 0.0,
                igst: $tax,
                cgstRate: 0.0,
                sgstRate: 0.0,
                igstRate: $gstPercentage,
                intraState: false,
            );
        }

        if ($this->money($split->cgst + $split->sgst + $split->igst) !== $tax) {
            throw ValidationException::withMessages([
                'gst' => self::COMPONENTS_MISMATCH,
            ]);
        }

        return $split;
    }

    private function money(float $amount): float
    {
        return round($amount, 2);
    }
}
