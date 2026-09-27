<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\ServiceOrder;
use App\Models\StatutoryInvoice;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;

/**
 * Resolves authoritative structured billing snapshots for statutory invoices.
 * Does not parse free-text addresses or read mutable customer profiles.
 */
final class StatutoryBillingStructuredResolver
{
    /**
     * @return array<string, mixed>|null Normalized structured billing when IRN-complete.
     */
    public function resolveForInvoice(StatutoryInvoice $invoice): ?array
    {
        $fromInvoice = StatutoryBillingStructured::fromStored($invoice->billing_address_structured);
        if ($fromInvoice !== null && StatutoryBillingStructured::isCompleteForIrn($fromInvoice)) {
            return $fromInvoice;
        }

        foreach ($this->sourceCandidates($invoice) as $candidate) {
            if ($candidate !== null && StatutoryBillingStructured::isCompleteForIrn($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function missingIrnFieldLabels(StatutoryInvoice $invoice): array
    {
        if ($this->resolveForInvoice($invoice) !== null) {
            return [];
        }

        $bestPartial = StatutoryBillingStructured::fromStored($invoice->billing_address_structured);
        foreach ($this->sourceCandidates($invoice) as $candidate) {
            if ($candidate !== null) {
                $bestPartial = $candidate;

                break;
            }
        }

        if ($bestPartial === null) {
            return ['billing_address_structured'];
        }

        $missing = [];
        if (StatutoryBillingStructured::nullable($bestPartial['city'] ?? null) === null) {
            $missing[] = 'city';
        }
        if (StatutoryBillingStructured::nullable($bestPartial['state'] ?? null) === null) {
            $missing[] = 'state';
        }
        if (StatutoryBillingStructured::pin($bestPartial['pincode'] ?? null) === null) {
            $missing[] = 'pincode';
        }

        return $missing === [] ? ['billing_address_structured'] : $missing;
    }

    /**
     * @return list<array<string, mixed>|null>
     */
    private function sourceCandidates(StatutoryInvoice $invoice): array
    {
        return [
            $this->structuredFromCommerce($invoice),
            $this->structuredFromPosSale($invoice),
            $this->structuredFromServiceOrder($invoice),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function structuredFromCommerce(StatutoryInvoice $invoice): ?array
    {
        if ((string) $invoice->source_type !== StatutoryInvoiceSourceType::CommerceOrder->value) {
            return null;
        }

        $order = CommerceOrder::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->first()
            ?? CommerceOrder::query()
                ->where('channel', $invoice->channel)
                ->where('source_id', $invoice->source_id)
                ->first();

        return StatutoryBillingStructured::fromStored($order?->billing_address_structured);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function structuredFromPosSale(StatutoryInvoice $invoice): ?array
    {
        if ((string) $invoice->source_type !== StatutoryInvoiceSourceType::InventorySale->value) {
            return null;
        }

        $invoice->loadMissing('inventorySale');
        $sale = $invoice->inventorySale;
        if ($sale === null) {
            return null;
        }

        return StatutoryBillingStructured::fromStored($sale->billing_address_structured);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function structuredFromServiceOrder(StatutoryInvoice $invoice): ?array
    {
        if ((string) $invoice->source_type !== StatutoryInvoiceSourceType::ServiceOrder->value) {
            return null;
        }

        $order = ServiceOrder::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->first()
            ?? ServiceOrder::query()
                ->where('order_number', $invoice->source_id)
                ->first();

        return StatutoryBillingStructured::fromStored($order?->billing_address_structured);
    }
}
