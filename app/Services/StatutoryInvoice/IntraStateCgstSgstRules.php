<?php

namespace App\Services\StatutoryInvoice;

use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;

/**
 * Intra-state CGST/SGST allocation aligned with NIC IRP rules.
 *
 * VERIFIED (NIC IRP KB, errors 2227 and 2234): for each intra-state line,
 * CGST and SGST amounts must be equal. Component amounts should equal
 * taxable × rate ÷ 2 with a documented ±₹1 tolerance on rate identity (2234).
 *
 * When authoritative line tax_total has odd paise, equal half-rounding produces
 * cgst = sgst = round(tax ÷ 2, 2) and cgst + sgst may exceed tax_total by 1 paisa.
 * tax_total remains the commerce-authoritative lump; components are not mapper-adjusted.
 */
final class IntraStateCgstSgstRules
{
    public const INTRA_STATE_CGST_SGST_UNEQUAL = 'intra_state_cgst_sgst_unequal';

    /**
     * @return array{0: float, 1: float}
     */
    public static function equalHalves(float $taxTotal): array
    {
        $half = round($taxTotal / 2, 2);

        return [$half, $half];
    }

    public static function paise(float $amount): int
    {
        return (int) round(round($amount, 2) * 100);
    }

    /**
     * Drift between equal halves and authoritative tax (0 or 1 paise per line).
     */
    public static function componentSumDriftPaise(float $taxTotal): int
    {
        $taxPaise = self::paise($taxTotal);
        if ($taxPaise === 0) {
            return 0;
        }

        [$cgst, $sgst] = self::equalHalves($taxTotal);

        return abs(self::paise($cgst) + self::paise($sgst) - $taxPaise);
    }

    public static function isIntraStateLine(?float $cgst, ?float $sgst, ?float $igst): bool
    {
        if (self::paise((float) $igst) > 0) {
            return false;
        }

        return self::paise((float) $cgst) > 0 || self::paise((float) $sgst) > 0;
    }

    public static function maxAllowedInvoiceComponentDriftPaise(StatutoryInvoice $invoice): int
    {
        $invoice->loadMissing('items');
        $drift = 0;

        foreach ($invoice->items as $item) {
            if (! self::isIntraStateLine($item->cgst, $item->sgst, $item->igst)) {
                continue;
            }

            $drift += self::componentSumDriftPaise((float) $item->tax_total);
        }

        return $drift;
    }

    public static function lineHasEqualComponents(StatutoryInvoiceItem $item): bool
    {
        if (! self::isIntraStateLine($item->cgst, $item->sgst, $item->igst)) {
            return true;
        }

        return self::paise((float) $item->cgst) === self::paise((float) $item->sgst);
    }
}
