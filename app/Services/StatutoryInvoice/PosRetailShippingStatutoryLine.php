<?php

namespace App\Services\StatutoryInvoice;

use App\Models\StatutoryInvoice;

/**
 * POS retail shipping line helpers for e-invoice reconciliation (read-only / mapping).
 */
final class PosRetailShippingStatutoryLine
{
    /** Internal sentinel — not a catalog SKU. */
    public const LINE_SKU = '__desk_pos_shipping__';

    public static function invoiceHasShippingLine(StatutoryInvoice $invoice): bool
    {
        $invoice->loadMissing('items');
        foreach ($invoice->items as $item) {
            if ((string) $item->sku === self::LINE_SKU) {
                return true;
            }
        }

        return false;
    }

    /**
     * New mints with a persisted shipping line: line GST and invoice total match header snapshots.
     */
    public static function persistedLinesReconcile(StatutoryInvoice $invoice): bool
    {
        if (! self::invoiceHasShippingLine($invoice)) {
            return false;
        }

        $invoice->loadMissing('items');
        if ($invoice->items->isEmpty()) {
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

        if ($lineCgst !== $headerCgst || $lineSgst !== $headerSgst || $lineIgst !== $headerIgst) {
            return false;
        }

        $shipping = round(max(0, (float) ($invoice->shipping_amount ?? 0)), 2);
        $expectedInvoicePaise = IntraStateCgstSgstRules::moneyPaise((float) $invoice->taxable_value)
            + IntraStateCgstSgstRules::moneyPaise($shipping)
            + IntraStateCgstSgstRules::moneyPaise((float) $invoice->tax_total)
            + IntraStateCgstSgstRules::moneyPaise((float) $invoice->rounding);

        return $expectedInvoicePaise === IntraStateCgstSgstRules::moneyPaise((float) $invoice->invoice_value);
    }
}
