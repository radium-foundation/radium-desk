<?php

namespace App\Services\StatutoryInvoice;

use App\Services\StatutoryInvoice\Data\GstComponentSplit;
use App\Support\Finance\GstStateCodes;
use Illuminate\Validation\ValidationException;

/**
 * Decomposes an authoritative exclusive GST lump into CGST/SGST or IGST.
 *
 * Intra-State vs inter-State follows IGST Act ss.7–8: seller GST state versus
 * place of supply. billing_state is not used. The stored tax_total is never
 * rewritten. Intra-state uses IntraStateCgstSgstRules (NIC IRP 2227/2234).
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

    /**
     * Inclusive hardware lines may be 1 paisa off exclusive identity
     * after GST = gross − round(gross / (1 + rate), 2). Default 0 keeps
     * exclusive service invoices fail-closed.
     */
    public function splitLine(
        string $sellerGstStateCode,
        ?string $placeOfSupplyState,
        ?float $gstPercentage,
        float $taxableValue,
        float $taxTotal,
        int $exclusivePaisaTolerance = 0,
    ): GstComponentSplit {
        $this->assertLineInputs($sellerGstStateCode, $placeOfSupplyState, $gstPercentage, $taxableValue, $taxTotal, $exclusivePaisaTolerance);

        $placeCode = GstStateCodes::codeForName(trim((string) $placeOfSupplyState));
        $intraState = trim($sellerGstStateCode) === $placeCode;
        $tax = $this->money($taxTotal);

        if ($intraState) {
            $taxPaise = IntraStateCgstSgstRules::moneyPaise($tax);
            $taxablePaise = IntraStateCgstSgstRules::moneyPaise($taxableValue);
            $ideal = IntraStateCgstSgstRules::idealHalfFromTaxablePaise($taxablePaise, (float) $gstPercentage);
            $headerHalf = IntraStateCgstSgstRules::headerHalfFromTotalTaxPaise($taxPaise);
            [$halfPaise] = IntraStateCgstSgstRules::allocateLineHalfPaise($headerHalf, [$ideal]);
            IntraStateCgstSgstRules::assertMintSnapshot($taxPaise, [$halfPaise]);
            $half = IntraStateCgstSgstRules::fromPaise($halfPaise);
            $halfRate = (float) $gstPercentage / 2;

            return new GstComponentSplit(
                cgst: $half,
                sgst: $half,
                igst: 0.0,
                cgstRate: $halfRate,
                sgstRate: $halfRate,
                igstRate: 0.0,
                intraState: true,
            );
        }

        return new GstComponentSplit(
            cgst: 0.0,
            sgst: 0.0,
            igst: $tax,
            cgstRate: 0.0,
            sgstRate: 0.0,
            igstRate: (float) $gstPercentage,
            intraState: false,
        );
    }

    /**
     * @param  list<array{taxableValue: float, taxTotal: float, gstPercentage: float}>  $lines
     * @return list<array{0: float, 1: float, 2: float}>
     */
    public function splitIntraStateLines(
        string $sellerGstStateCode,
        ?string $placeOfSupplyState,
        array $lines,
        int $exclusivePaisaTolerance = 0,
    ): array {
        if ($lines === []) {
            return [];
        }

        $idealHalves = [];
        $totalTaxPaise = 0;

        foreach ($lines as $line) {
            $this->assertLineInputs(
                $sellerGstStateCode,
                $placeOfSupplyState,
                $line['gstPercentage'],
                $line['taxableValue'],
                $line['taxTotal'],
                $exclusivePaisaTolerance,
            );

            $taxPaise = IntraStateCgstSgstRules::moneyPaise($line['taxTotal']);
            $taxablePaise = IntraStateCgstSgstRules::moneyPaise($line['taxableValue']);
            $idealHalves[] = IntraStateCgstSgstRules::idealHalfFromTaxablePaise(
                $taxablePaise,
                (float) $line['gstPercentage'],
            );
            $totalTaxPaise += $taxPaise;
        }

        $headerHalf = IntraStateCgstSgstRules::headerHalfFromTotalTaxPaise($totalTaxPaise);
        $allocated = IntraStateCgstSgstRules::allocateLineHalfPaise($headerHalf, $idealHalves);
        IntraStateCgstSgstRules::assertMintSnapshot($totalTaxPaise, $allocated);

        $components = [];
        foreach ($allocated as $halfPaise) {
            $half = IntraStateCgstSgstRules::fromPaise($halfPaise);
            $components[] = [$half, $half, 0.0];
        }

        return $components;
    }

    private function assertLineInputs(
        string $sellerGstStateCode,
        ?string $placeOfSupplyState,
        ?float $gstPercentage,
        float $taxableValue,
        float $taxTotal,
        int $exclusivePaisaTolerance,
    ): void {
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

        if (GstStateCodes::codeForName($place) === null) {
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
            $exclusiveDelta = abs($this->paise($expected) - $this->paise($tax));
            if ($exclusiveDelta > max(0, $exclusivePaisaTolerance)) {
                throw ValidationException::withMessages([
                    'gst' => self::TAX_MISMATCH,
                ]);
            }
        }
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
