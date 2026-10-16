<?php

namespace App\Support\Inventory;

use App\Enums\InventorySaleStatus;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\InventorySale;
use App\Models\StatutoryInvoice;
use Illuminate\Validation\ValidationException;

/**
 * Fail-closed guards so POS inventory changes cannot bypass statutory cancellation.
 */
final class PosSaleStatutoryReversalGuard
{
    public static function assertReturnAllowed(InventorySale $sale): void
    {
        self::assertInventoryMutationAllowed(
            $sale,
            'Return cannot be used for this sale while a GST invoice record exists in this state. Use Cancel sale when the invoice is issued, or follow the statutory cancellation record when the invoice is already cancelled.',
        );
    }

    public static function assertSerialExchangeAllowed(InventorySale $sale): void
    {
        self::assertInventoryMutationAllowed(
            $sale,
            'Serial exchange cannot change inventory for this sale while a GST invoice record exists in this state. Use Cancel sale when the invoice is issued, or follow the statutory cancellation record when the invoice is already cancelled.',
        );
    }

    private static function assertInventoryMutationAllowed(InventorySale $sale, string $message): void
    {
        $invoice = self::resolveLinkedInvoice($sale);
        if ($invoice === null) {
            return;
        }

        if ($invoice->status === StatutoryInvoiceStatus::Issued) {
            throw ValidationException::withMessages(['sale' => $message]);
        }

        if ($invoice->status === StatutoryInvoiceStatus::Cancelled) {
            if ($sale->status === InventorySaleStatus::Completed) {
                throw ValidationException::withMessages(['sale' => $message]);
            }

            return;
        }

        throw ValidationException::withMessages(['sale' => $message]);
    }

    private static function resolveLinkedInvoice(InventorySale $sale): ?StatutoryInvoice
    {
        $sale->loadMissing('statutoryInvoice');
        $invoice = $sale->statutoryInvoice;
        if ($invoice !== null) {
            return $invoice;
        }

        return StatutoryInvoice::query()
            ->where('inventory_sale_id', $sale->id)
            ->first();
    }
}
