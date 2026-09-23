<?php

namespace App\Services\StatutoryInvoice;

use App\Models\CommerceOrderItem;
use App\Support\HardwareFulfilment\HardwareConfigurableVariantDisplay;
use Illuminate\Support\Carbon;

/**
 * Statutory invoice line inclusion and description for commerce orders.
 *
 * Service lines use stored commerce descriptions. Hardware keeps variant display.
 * Zero-value duration/callback add-on placeholders from storefront GenrateOrder
 * are excluded when they were not purchased.
 */
final class StatutoryInvoiceCommerceLinePresentation
{
    public function includesOnStatutoryInvoice(CommerceOrderItem $item): bool
    {
        if ($item->shipping_line_kind === 'physical_merchandise') {
            return true;
        }

        if ($this->hasMaterialValue($item)) {
            return true;
        }

        return ! $this->isUnselectedOptionalAddOn($item);
    }

    public function invoiceDescription(CommerceOrderItem $item, ?Carbon $commercialAt = null): string
    {
        if ($item->shipping_line_kind === 'physical_merchandise') {
            return HardwareConfigurableVariantDisplay::invoiceDescription($item);
        }

        $description = trim((string) $item->description);
        if ($description !== '') {
            return RdServiceStatutoryDisplayName::normalizeInvoiceDescription($description, $commercialAt);
        }

        return HardwareConfigurableVariantDisplay::invoiceDescription($item);
    }

    private function hasMaterialValue(CommerceOrderItem $item): bool
    {
        foreach (['taxable_value', 'tax_total', 'line_total', 'unit_price'] as $field) {
            if (abs((float) ($item->{$field} ?? 0)) >= 0.005) {
                return true;
            }
        }

        return false;
    }

    private function isUnselectedOptionalAddOn(CommerceOrderItem $item): bool
    {
        $description = strtolower(trim((string) $item->description));

        if ($description === 'not required') {
            return true;
        }

        if (str_contains($description, 'rd technical support')) {
            return true;
        }

        if (str_contains($description, 'call back') && str_contains($description, 'not required')) {
            return true;
        }

        return false;
    }
}
