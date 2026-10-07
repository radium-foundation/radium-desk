<?php

namespace App\Reports\CaMonthly;

use App\Models\CommerceOrder;
use App\Models\InventorySale;
use App\Models\ServiceOrder;
use App\Models\StatutoryInvoice;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;

/**
 * Resolves buyer State for the Sales Report register.
 *
 * Precedence (first non-empty authoritative value wins):
 * 1. Statutory invoice billing snapshot structured state
 * 2. Linked commerce order structured billing state
 * 3. Linked commerce order billing_state column
 * 4. Statutory invoice place_of_supply_state (minted POS authority)
 * 5. Linked POS inventory sale structured billing state
 * 6. Linked service order structured billing state
 * 7. Linked service order billing_state or place_of_supply_state
 *
 * GSTIN-derived state is intentionally excluded. Place of supply does not override
 * commerce billing state when a linked commerce order supplies billing state.
 */
final class CaMonthlyReportStateResolver
{
    public function resolve(
        StatutoryInvoice $invoice,
        ?CommerceOrder $commerceOrder = null,
        ?ServiceOrder $serviceOrder = null,
        ?InventorySale $sale = null,
    ): ?string {
        $fromInvoice = $this->stateFromStructured($invoice->billing_address_structured);
        if ($fromInvoice !== null) {
            return $fromInvoice;
        }

        if ($commerceOrder !== null) {
            $fromCommerceStructured = $this->stateFromStructured($commerceOrder->billing_address_structured);
            if ($fromCommerceStructured !== null) {
                return $fromCommerceStructured;
            }

            $billingState = $this->nullableString($commerceOrder->billing_state);
            if ($billingState !== null) {
                return $billingState;
            }
        }

        $fromPlaceOfSupply = $this->nullableString($invoice->place_of_supply_state);
        if ($fromPlaceOfSupply !== null) {
            return $fromPlaceOfSupply;
        }

        $sale ??= $invoice->relationLoaded('inventorySale')
            ? $invoice->inventorySale
            : null;

        if ($sale !== null) {
            $fromSale = $this->stateFromStructured($sale->billing_address_structured);
            if ($fromSale !== null) {
                return $fromSale;
            }
        }

        if ($serviceOrder !== null) {
            $fromServiceStructured = $this->stateFromStructured($serviceOrder->billing_address_structured);
            if ($fromServiceStructured !== null) {
                return $fromServiceStructured;
            }

            $serviceBillingState = $this->nullableString($serviceOrder->billing_state)
                ?? $this->nullableString($serviceOrder->place_of_supply_state);
            if ($serviceBillingState !== null) {
                return $serviceBillingState;
            }
        }

        return null;
    }

    private function stateFromStructured(mixed $structured): ?string
    {
        $parsed = StatutoryBillingStructured::fromStored($structured);

        return StatutoryBillingStructured::nullable($parsed['state'] ?? null);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
