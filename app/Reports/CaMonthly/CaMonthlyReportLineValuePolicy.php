<?php

namespace App\Reports\CaMonthly;

use App\Models\StatutoryInvoiceItem;

/**
 * Excludes zero-value included/support lines from CA export detail.
 */
final class CaMonthlyReportLineValuePolicy
{
    public function isExportable(StatutoryInvoiceItem $item): bool
    {
        $taxable = round((float) $item->taxable_value, 2);
        $igst = round((float) ($item->igst ?? 0), 2);
        $cgst = round((float) ($item->cgst ?? 0), 2);
        $sgst = round((float) ($item->sgst ?? 0), 2);
        $lineTotal = round((float) $item->line_total, 2);

        return ! ($taxable === 0.0
            && $igst === 0.0
            && $cgst === 0.0
            && $sgst === 0.0
            && $lineTotal === 0.0);
    }

    /**
     * @param  iterable<int, StatutoryInvoiceItem>  $items
     * @return list<StatutoryInvoiceItem>
     */
    public function filterExportable(iterable $items): array
    {
        $exportable = [];
        foreach ($items as $item) {
            if ($this->isExportable($item)) {
                $exportable[] = $item;
            }
        }

        return $exportable;
    }
}
