<?php

namespace App\Services\StatutoryInvoice;

use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use Illuminate\Validation\ValidationException;

/**
 * Intra-state CGST/SGST allocation aligned with NIC IRP rules.
 *
 * VERIFIED (NIC IRP KB):
 * - 2227: CGST and SGST must be equal on every line and at invoice header.
 * - 2234: line components should equal taxable × (rate ÷ 2) within ±₹1.
 *
 * INVARIANT (future mint):
 * 1. Each line: cgst_paise === sgst_paise.
 * 2. Header cgst_paise === sum(line cgst_paise); header sgst_paise === sum(line sgst_paise).
 * 3. Header cgst_paise === header sgst_paise.
 * 4. Header cgst_paise + header sgst_paise reconciles to authoritative tax_total within
 *    maxInvoiceComponentDriftPaise() — 0 when invoice tax is even paise, 1 when odd.
 * 5. Multi-line drift does not accumulate per line; reconciliation is invoice-level.
 *
 * Algorithm:
 * - idealHalfPaise(line) = round(taxable_paise × gst% ÷ 200)  [IRP 2234 basis]
 * - headerHalfPaise = ⌈tax_total_paise ÷ 2⌉ using intdiv(tax + tax%2, 2)
 * - allocate ideals to headerHalf via deterministic ±1 paise adjustment (last lines first)
 */
final class IntraStateCgstSgstRules
{
    public const INTRA_STATE_CGST_SGST_UNEQUAL = 'intra_state_cgst_sgst_unequal';

    public const GST_COMPONENTS_MISMATCH = 'gst_components_mismatch';

    public const LINE_HEADER_GST_MISMATCH = 'line_header_gst_mismatch';

    /**
     * IRP 2234 basis: round(taxable × (gst% ÷ 2) ÷ 100, 2) in paise.
     */
    public static function idealHalfFromTaxablePaise(int $taxablePaise, float $gstPercentage): int
    {
        if ($taxablePaise <= 0 || $gstPercentage <= 0) {
            return 0;
        }

        return (int) round($taxablePaise * $gstPercentage / 200);
    }

    /**
     * Equal header half in paise where 2 × half matches even tax exactly, or tax + 1 when odd.
     */
    public static function headerHalfFromTotalTaxPaise(int $totalTaxPaise): int
    {
        if ($totalTaxPaise <= 0) {
            return 0;
        }

        return intdiv($totalTaxPaise + ($totalTaxPaise % 2), 2);
    }

    /**
     * Maximum |cgst + sgst − tax_total| at invoice level (0 or 1 paise).
     */
    public static function maxInvoiceComponentDriftPaise(int $totalTaxPaise): int
    {
        return $totalTaxPaise % 2;
    }

    /**
     * @param  list<int>  $idealHalfPaisePerLine  indexed 0..n-1 in line order
     * @return list<int>
     */
    public static function allocateLineHalfPaise(int $headerHalfPaise, array $idealHalfPaisePerLine): array
    {
        if ($idealHalfPaisePerLine === []) {
            return [];
        }

        $allocated = array_values($idealHalfPaisePerLine);
        $delta = $headerHalfPaise - array_sum($allocated);

        if ($delta === 0) {
            return $allocated;
        }

        $indices = array_keys($allocated);

        if ($delta > 0) {
            for ($offset = 0; $offset < $delta; $offset++) {
                $allocated[$indices[$offset % count($indices)]]++;
            }

            return $allocated;
        }

        $indices = array_reverse($indices);
        for ($offset = 0; $offset < abs($delta); $offset++) {
            $allocated[$indices[$offset % count($indices)]]--;
        }

        return $allocated;
    }

    /**
     * @param  list<int>  $lineHalfPaise
     */
    public static function assertMintSnapshot(int $totalTaxPaise, array $lineHalfPaise): void
    {
        if ($lineHalfPaise === []) {
            return;
        }

        foreach ($lineHalfPaise as $half) {
            if ($half < 0) {
                throw ValidationException::withMessages([
                    'gst' => self::GST_COMPONENTS_MISMATCH,
                ]);
            }
        }

        $headerHalf = self::headerHalfFromTotalTaxPaise($totalTaxPaise);
        $lineCgstSum = array_sum($lineHalfPaise);
        $lineSgstSum = $lineCgstSum;

        if ($lineCgstSum !== $headerHalf || $lineSgstSum !== $headerHalf) {
            throw ValidationException::withMessages([
                'gst' => self::LINE_HEADER_GST_MISMATCH,
            ]);
        }

        $componentSum = $lineCgstSum + $lineSgstSum;
        $allowedDrift = self::maxInvoiceComponentDriftPaise($totalTaxPaise);
        if (abs($componentSum - $totalTaxPaise) > $allowedDrift) {
            throw ValidationException::withMessages([
                'gst' => self::GST_COMPONENTS_MISMATCH,
            ]);
        }
    }

    public static function moneyPaise(float $amount): int
    {
        return (int) round(round($amount, 2) * 100);
    }

    public static function fromPaise(int $paise): float
    {
        return round($paise / 100, 2);
    }

    public static function isIntraStateLine(?float $cgst, ?float $sgst, ?float $igst): bool
    {
        if (self::moneyPaise((float) $igst) > 0) {
            return false;
        }

        return self::moneyPaise((float) $cgst) > 0 || self::moneyPaise((float) $sgst) > 0;
    }

    public static function lineHasEqualComponents(StatutoryInvoiceItem $item): bool
    {
        if (! self::isIntraStateLine($item->cgst, $item->sgst, $item->igst)) {
            return true;
        }

        return self::moneyPaise((float) $item->cgst) === self::moneyPaise((float) $item->sgst);
    }

    /**
     * @return list<string>
     */
    public static function storedInvoiceReasons(StatutoryInvoice $invoice): array
    {
        $invoice->loadMissing('items');
        $reasons = [];

        if (! self::isIntraStateLine(
            (float) $invoice->cgst,
            (float) $invoice->sgst,
            (float) $invoice->igst,
        )) {
            return $reasons;
        }

        $headerCgst = self::moneyPaise((float) $invoice->cgst);
        $headerSgst = self::moneyPaise((float) $invoice->sgst);
        $headerTax = self::moneyPaise((float) $invoice->tax_total);

        if ($headerCgst !== $headerSgst) {
            $reasons[] = self::INTRA_STATE_CGST_SGST_UNEQUAL;
        }

        $lineCgst = 0;
        $lineSgst = 0;
        foreach ($invoice->items as $item) {
            if (! self::lineHasEqualComponents($item)) {
                $reasons[] = self::INTRA_STATE_CGST_SGST_UNEQUAL;
            }
            $lineCgst += self::moneyPaise((float) $item->cgst);
            $lineSgst += self::moneyPaise((float) $item->sgst);
        }

        if ($lineCgst !== $headerCgst || $lineSgst !== $headerSgst) {
            $reasons[] = self::LINE_HEADER_GST_MISMATCH;
        }

        $allowedDrift = self::maxInvoiceComponentDriftPaise($headerTax);
        if (abs($headerCgst + $headerSgst - $headerTax) > $allowedDrift) {
            $reasons[] = self::GST_COMPONENTS_MISMATCH;
        }

        return array_values(array_unique($reasons));
    }
}
