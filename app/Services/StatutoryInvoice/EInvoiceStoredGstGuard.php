<?php

namespace App\Services\StatutoryInvoice;

use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;

/**
 * Stored statutory GST only. Does not recalculate historical tax.
 */
final class EInvoiceStoredGstGuard
{
    /**
     * @return list<string>
     */
    public static function missingReasons(StatutoryInvoice $invoice): array
    {
        $invoice->loadMissing('items');

        $reasons = [];

        foreach (['cgst', 'sgst', 'igst'] as $field) {
            if ($invoice->{$field} === null) {
                $reasons[] = 'missing_'.$field;
            }
        }

        foreach ($invoice->items as $item) {
            $reasons = array_merge($reasons, self::itemReasons($item));
        }

        if ($invoice->items->isEmpty()) {
            $reasons[] = 'missing_lines';
        }
        if ($invoice->taxable_value === null) {
            $reasons[] = 'missing_taxable_value';
        }
        if ($invoice->tax_total === null) {
            $reasons[] = 'missing_tax_total';
        }
        if ($invoice->invoice_value === null) {
            $reasons[] = 'missing_invoice_value';
        }

        if ($reasons !== []) {
            return array_values(array_unique($reasons));
        }

        $headerCgst = self::paise($invoice->cgst);
        $headerSgst = self::paise($invoice->sgst);
        $headerIgst = self::paise($invoice->igst);
        $headerTax = self::paise($invoice->tax_total);
        if ($headerCgst + $headerSgst + $headerIgst !== $headerTax) {
            $reasons[] = 'gst_components_mismatch';
        }

        $lineCgst = 0;
        $lineSgst = 0;
        $lineIgst = 0;
        foreach ($invoice->items as $item) {
            $lineCgst += self::paise($item->cgst);
            $lineSgst += self::paise($item->sgst);
            $lineIgst += self::paise($item->igst);
        }
        if ($lineCgst !== $headerCgst || $lineSgst !== $headerSgst || $lineIgst !== $headerIgst) {
            $reasons[] = 'line_header_gst_mismatch';
        }

        $expectedTotal = self::paise($invoice->taxable_value)
            + self::paise($invoice->tax_total)
            + self::paise($invoice->rounding);
        if ($expectedTotal !== self::paise($invoice->invoice_value)) {
            $reasons[] = 'invoice_total_mismatch';
        }

        return array_values(array_unique($reasons));
    }

    public static function isComplete(StatutoryInvoice $invoice): bool
    {
        return self::missingReasons($invoice) === [];
    }

    /**
     * @return list<string>
     */
    private static function itemReasons(StatutoryInvoiceItem $item): array
    {
        $reasons = [];
        if ($item->cgst === null) {
            $reasons[] = 'missing_line_cgst';
        }
        if ($item->sgst === null) {
            $reasons[] = 'missing_line_sgst';
        }
        if ($item->igst === null) {
            $reasons[] = 'missing_line_igst';
        }
        if ($item->gst_percentage === null) {
            $reasons[] = 'missing_gst_rate';
        }
        if ($item->taxable_value === null) {
            $reasons[] = 'missing_line_taxable';
        }

        return $reasons;
    }

    private static function paise(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
