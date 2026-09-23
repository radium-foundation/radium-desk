<?php

namespace App\Services\StatutoryInvoice;

use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use Illuminate\Support\Collection;

/**
 * Canonical billable commerce lines for statutory invoice eligibility and mint.
 *
 * Delegates inclusion to StatutoryInvoiceCommerceLinePresentation so suppression
 * rules stay in one place.
 */
final class StatutoryInvoiceCommerceBillableLines
{
    public const NO_BILLABLE_LINES = 'No billable statutory invoice lines remain after optional add-on suppression.';

    public function __construct(
        private readonly StatutoryInvoiceCommerceLinePresentation $presentation,
    ) {}

    /**
     * @return Collection<int, CommerceOrderItem>
     */
    public function forOrder(CommerceOrder $order): Collection
    {
        $order->loadMissing('items');

        return $order->items
            ->filter(fn (CommerceOrderItem $item): bool => $this->presentation->includesOnStatutoryInvoice($item))
            ->values();
    }

    public function hasAny(CommerceOrder $order): bool
    {
        return $this->forOrder($order)->isNotEmpty();
    }
}
