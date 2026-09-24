<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceSourceType;
use App\Models\Order;
use App\Models\StatutoryInvoice;

/**
 * Resolves the Desk service {@see Order} linked to a statutory invoice, when one exists.
 *
 * POS-only statutory invoices ({@see StatutoryInvoiceSourceType::InventorySale}) do not
 * resolve here — they are handled by the refund-review POS boundary instead.
 */
final class StatutoryInvoiceLinkedOrderResolver
{
    public function resolve(StatutoryInvoice $invoice): ?Order
    {
        if ($invoice->support_order_id !== null) {
            $order = Order::query()->find($invoice->support_order_id);
            if ($order instanceof Order) {
                return $order;
            }
        }

        if ($invoice->source_type !== StatutoryInvoiceSourceType::CommerceOrder) {
            return null;
        }

        $candidates = array_values(array_unique(array_filter([
            trim((string) ($invoice->source_order_id ?? '')),
            trim((string) ($invoice->source_id ?? '')),
        ])));

        foreach ($candidates as $sourceId) {
            $order = Order::query()
                ->where('order_id', $sourceId)
                ->orderByDesc('id')
                ->first();

            if ($order instanceof Order) {
                return $order;
            }
        }

        return null;
    }
}
