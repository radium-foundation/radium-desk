<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\StatutoryInvoice;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;

/**
 * Issued invoice snapshot first; commerce/POS fallbacks for historical rows.
 */
final class StatutoryInvoiceBillingSnapshot
{
    /**
     * @return array<string, mixed>|null
     */
    public function structured(StatutoryInvoice $invoice): ?array
    {
        $fromInvoice = StatutoryBillingStructured::fromStored($invoice->billing_address_structured);
        if ($fromInvoice !== null) {
            return $fromInvoice;
        }

        return $this->commerceStructured($invoice) ?? $this->posStructured($invoice);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function commerceStructured(StatutoryInvoice $invoice): ?array
    {
        if ((string) $invoice->source_type !== StatutoryInvoiceSourceType::CommerceOrder->value) {
            return null;
        }

        $order = CommerceOrder::query()->where('statutory_invoice_id', $invoice->id)->first();
        if ($order === null) {
            $order = CommerceOrder::query()
                ->where('channel', $invoice->channel)
                ->where('source_id', $invoice->source_id)
                ->first();
        }

        return StatutoryBillingStructured::fromStored($order?->billing_address_structured);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function posStructured(StatutoryInvoice $invoice): ?array
    {
        if ((string) $invoice->source_type !== StatutoryInvoiceSourceType::InventorySale->value) {
            return null;
        }

        $invoice->loadMissing('inventorySale');

        return StatutoryBillingStructured::fromStored($invoice->inventorySale?->billing_address_structured);
    }
}
