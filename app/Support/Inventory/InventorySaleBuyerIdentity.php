<?php

namespace App\Support\Inventory;

use App\Models\InventorySale;

final class InventorySaleBuyerIdentity
{
    public static function nameForStatutory(InventorySale $sale): ?string
    {
        if (filled($sale->buyer_name)) {
            return trim((string) $sale->buyer_name);
        }

        return $sale->customer?->name;
    }

    public static function displayName(InventorySale $sale): string
    {
        if (filled($sale->buyer_name)) {
            return trim((string) $sale->buyer_name);
        }

        if ($sale->statutory_invoice_id !== null) {
            $sale->loadMissing('statutoryInvoice');
            if (filled($sale->statutoryInvoice?->buyer_name)) {
                return (string) $sale->statutoryInvoice->buyer_name;
            }
        }

        return (string) ($sale->customer?->name ?? 'Customer');
    }
}
