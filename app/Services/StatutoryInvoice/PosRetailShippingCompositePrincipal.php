<?php

namespace App\Services\StatutoryInvoice;

use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;

/**
 * Composite-supply principal goods line for POS retail header shipping (e-Invoice mapping only).
 *
 * Active only when Owner enables STATUTORY_POS_RETAIL_SHIPPING_COMPOSITE_SUPPLY. Desk does not
 * treat legal classification as approved without that flag and CA/Finance sign-off.
 */
final class PosRetailShippingCompositePrincipal
{
    /**
     * @return array{hsn_sac: string, gst_percentage: float}|null
     */
    public static function resolve(StatutoryInvoice $invoice): ?array
    {
        if (self::unresolvedReasons($invoice) !== []) {
            return null;
        }

        $invoice->loadMissing('items');
        $principal = null;
        $principalTaxable = -1.0;

        foreach (self::productLines($invoice) as $item) {
            $taxable = (float) $item->taxable_value;
            if ($taxable > $principalTaxable) {
                $principalTaxable = $taxable;
                $principal = [
                    'hsn_sac' => trim((string) $item->hsn_sac),
                    'gst_percentage' => (float) $item->gst_percentage,
                ];
            } elseif ($taxable === $principalTaxable && $principal !== null) {
                $hsn = trim((string) $item->hsn_sac);
                if ($hsn !== $principal['hsn_sac']) {
                    return null;
                }
            }
        }

        return $principal;
    }

    /**
     * @return list<string>
     */
    public static function unresolvedReasons(StatutoryInvoice $invoice): array
    {
        $invoice->loadMissing('items');
        $lines = self::productLines($invoice);
        if ($lines === []) {
            return ['missing_pos_shipping_composite_principal_hsn'];
        }

        $uniqueRates = [];
        foreach ($lines as $item) {
            $uniqueRates[] = round((float) $item->gst_percentage, 2);
        }
        $uniqueRates = array_values(array_unique($uniqueRates, SORT_REGULAR));
        if (count($uniqueRates) > 1) {
            return ['pos_shipping_composite_mixed_gst_rates'];
        }

        $principalTaxable = -1.0;
        $principalHsn = null;

        foreach ($lines as $item) {
            $hsn = trim((string) ($item->hsn_sac ?? ''));
            if ($hsn === '') {
                return ['missing_pos_shipping_composite_principal_hsn'];
            }

            $taxable = (float) $item->taxable_value;
            if ($taxable > $principalTaxable) {
                $principalTaxable = $taxable;
                $principalHsn = $hsn;
            } elseif ($taxable === $principalTaxable && $principalHsn !== null && $hsn !== $principalHsn) {
                return ['pos_shipping_composite_principal_unresolved'];
            }
        }

        return [];
    }

    /**
     * @return list<StatutoryInvoiceItem>
     */
    private static function productLines(StatutoryInvoice $invoice): array
    {
        $lines = [];
        foreach ($invoice->items as $item) {
            if ((string) $item->sku === PosRetailShippingStatutoryLine::LINE_SKU) {
                continue;
            }

            $lines[] = $item;
        }

        return $lines;
    }
}
