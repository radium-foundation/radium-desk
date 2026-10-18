<?php

namespace App\Services\ReturnExchange;

use App\Models\InventorySaleSerial;
use App\Models\InventorySerial;
use App\Models\ReturnExchangeCase;

/**
 * Resolves verified per-unit value for a returned serial from persisted sale/fulfilment evidence.
 */
final class ReturnExchangeCaseSerialReplacementEquivalence
{
    public function verifiedUnitValueForReturnedSerial(
        ReturnExchangeCase $case,
        InventorySerial $returnedSerial,
    ): ?string {
        if ($case->inventory_sale_id !== null) {
            return $this->unitValueFromPosSale($case, $returnedSerial);
        }

        if ($case->hardware_fulfilment_id !== null) {
            return $this->unitValueFromFulfilment($case, $returnedSerial);
        }

        return null;
    }

    public function productsMatch(InventorySerial $returned, InventorySerial $replacement): bool
    {
        if ((int) $returned->product_id !== (int) $replacement->product_id) {
            return false;
        }

        $returnedVariant = $returned->variant_id;
        $replacementVariant = $replacement->variant_id;

        return (int) ($returnedVariant ?? 0) === (int) ($replacementVariant ?? 0);
    }

    private function unitValueFromPosSale(ReturnExchangeCase $case, InventorySerial $returnedSerial): ?string
    {
        $assignment = InventorySaleSerial::query()
            ->where('sale_id', $case->inventory_sale_id)
            ->where('serial_id', $returnedSerial->id)
            ->with('line')
            ->first();

        $line = $assignment?->line;
        if ($line === null) {
            return null;
        }

        $qty = max(1, (int) $line->qty);

        return number_format(round((float) $line->line_total / $qty, 2), 2, '.', '');
    }

    private function unitValueFromFulfilment(ReturnExchangeCase $case, InventorySerial $returnedSerial): ?string
    {
        $fulfilmentSerial = \App\Models\HardwareFulfilmentSerial::query()
            ->where('hardware_fulfilment_id', $case->hardware_fulfilment_id)
            ->where('inventory_serial_id', $returnedSerial->id)
            ->with('commerceOrderItem')
            ->first();

        $item = $fulfilmentSerial?->commerceOrderItem;
        if ($item === null || $item->line_total === null) {
            return null;
        }

        $qty = max(1, (int) ($item->qty ?? 1));

        return number_format(round((float) $item->line_total / $qty, 2), 2, '.', '');
    }
}
