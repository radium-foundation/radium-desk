<?php

namespace App\Services\StatutoryInvoice;

use App\Models\StatutoryInvoice;
use App\Support\Inventory\PosRetailShippingGst;

/**
 * POS retail shipping is stored on the statutory invoice header (shipping_amount) and
 * included in header tax totals at mint time, but not always persisted as a separate
 * statutory line. This helper reconciles that snapshot for e-invoice guards only.
 */
final class StatutoryInvoicePosShippingGstSnapshot
{
    public static function shippingAmount(StatutoryInvoice $invoice): float
    {
        return round(max(0, (float) ($invoice->shipping_amount ?? 0)), 2);
    }

    /**
     * True when header totals include POS shipping tax that is not on product lines.
     */
    public static function reconcilesHeaderOnlyShipping(StatutoryInvoice $invoice): bool
    {
        $shipping = self::shippingAmount($invoice);
        if ($shipping <= 0) {
            return false;
        }

        $invoice->loadMissing('items');
        if ($invoice->items->isEmpty()) {
            return false;
        }

        $maxRate = PosRetailShippingGst::maxLineGstRate(
            $invoice->items->map(fn ($item) => (float) $item->gst_percentage)->all(),
        );
        if ($maxRate <= 0) {
            return false;
        }

        $expectedShippingTaxPaise = IntraStateCgstSgstRules::moneyPaise(
            PosRetailShippingGst::taxOnExclusiveAmount($shipping, $maxRate),
        );
        if ($expectedShippingTaxPaise <= 0) {
            return false;
        }

        $lineCgst = 0;
        $lineSgst = 0;
        $lineIgst = 0;
        foreach ($invoice->items as $item) {
            $lineCgst += IntraStateCgstSgstRules::moneyPaise((float) $item->cgst);
            $lineSgst += IntraStateCgstSgstRules::moneyPaise((float) $item->sgst);
            $lineIgst += IntraStateCgstSgstRules::moneyPaise((float) $item->igst);
        }

        $headerCgst = IntraStateCgstSgstRules::moneyPaise((float) $invoice->cgst);
        $headerSgst = IntraStateCgstSgstRules::moneyPaise((float) $invoice->sgst);
        $headerIgst = IntraStateCgstSgstRules::moneyPaise((float) $invoice->igst);

        $deltaCgst = $headerCgst - $lineCgst;
        $deltaSgst = $headerSgst - $lineSgst;
        $deltaIgst = $headerIgst - $lineIgst;

        if (IntraStateCgstSgstRules::isIntraStateLine(
            (float) $invoice->cgst,
            (float) $invoice->sgst,
            (float) $invoice->igst,
        )) {
            if ($deltaCgst !== $deltaSgst || $deltaCgst < 0) {
                return false;
            }
            if ($deltaCgst + $deltaSgst !== $expectedShippingTaxPaise) {
                return false;
            }
        } else {
            if ($deltaCgst !== 0 || $deltaSgst !== 0 || $deltaIgst !== $expectedShippingTaxPaise) {
                return false;
            }
        }

        $expectedInvoicePaise = IntraStateCgstSgstRules::moneyPaise((float) $invoice->taxable_value)
            + IntraStateCgstSgstRules::moneyPaise($shipping)
            + IntraStateCgstSgstRules::moneyPaise((float) $invoice->tax_total)
            + IntraStateCgstSgstRules::moneyPaise((float) $invoice->rounding);

        if ($expectedInvoicePaise !== IntraStateCgstSgstRules::moneyPaise((float) $invoice->invoice_value)) {
            return false;
        }

        $shippingPaise = IntraStateCgstSgstRules::moneyPaise($shipping);
        if ($deltaCgst + $deltaSgst + $deltaIgst !== $expectedShippingTaxPaise) {
            return false;
        }

        return $shippingPaise > 0;
    }
}
