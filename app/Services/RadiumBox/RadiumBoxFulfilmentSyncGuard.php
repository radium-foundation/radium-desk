<?php

namespace App\Services\RadiumBox;

use App\Models\CommerceOrder;
use App\Models\Order;
use App\Support\BusinessOrderId;

/**
 * Prevents enrichment-only success from masking missing Box → Desk commerce handoff.
 */
final class RadiumBoxFulfilmentSyncGuard
{
    public function requiresCommerceHandoff(Order $order): bool
    {
        $parsed = BusinessOrderId::parse($order->order_id);

        return $parsed !== null
            && $parsed['owner'] === 'radiumbox.com'
            && $parsed['hardware'] === true;
    }

    public function hasCommerceHandoff(Order $order): bool
    {
        return CommerceOrder::query()
            ->where('source_id', $order->order_id)
            ->exists();
    }

    public function shouldMarkFullySynced(Order $order): bool
    {
        if (! $this->requiresCommerceHandoff($order)) {
            return true;
        }

        return $this->hasCommerceHandoff($order);
    }
}
