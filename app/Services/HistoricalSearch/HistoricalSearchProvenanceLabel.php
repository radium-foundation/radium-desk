<?php

namespace App\Services\HistoricalSearch;

class HistoricalSearchProvenanceLabel
{
    /**
     * @var array<string, string>
     */
    private const LINEAGE_LABELS = [
        'commerce_active' => 'RS/RQ commerce (old_final)',
        'commerce_box' => 'RadiumBox commerce',
        'rd_legacy' => 'RD legacy (rd_orders_old)',
        'rd_service' => 'RD service (partial)',
        'invoice' => 'Historical invoice',
        'serial' => 'Historical serial',
        'order_item' => 'Historical order item',
        'shipment' => 'Historical shipment',
        'customer' => 'Historical customer',
    ];

    public function forLineage(string $lineage, ?string $sourceDatabase = null): string
    {
        $label = self::LINEAGE_LABELS[$lineage] ?? $lineage;

        if ($sourceDatabase !== null && $sourceDatabase !== '') {
            return $label.' · '.$sourceDatabase;
        }

        return $label;
    }
}
