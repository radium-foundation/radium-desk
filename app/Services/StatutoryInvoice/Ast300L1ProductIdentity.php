<?php

namespace App\Services\StatutoryInvoice;

use App\Models\CommerceOrder;
use App\Models\Order;

/**
 * Identifies AST300 L1 rdservice.in hardware renewal orders.
 */
final class Ast300L1ProductIdentity
{
    public const PRODUCT_NAME = 'AST300 L1';

    public function matchesSupportOrder(Order $order): bool
    {
        return $this->normalizeProductName($order->product_name) === self::PRODUCT_NAME;
    }

    public function matchesCommerceMetadata(CommerceOrder $commerce): bool
    {
        $metadata = is_array($commerce->metadata) ? $commerce->metadata : [];
        $product = $metadata['product_name'] ?? null;

        return $this->normalizeProductName(is_scalar($product) ? (string) $product : null) === self::PRODUCT_NAME;
    }

    public function matches(Order $support, CommerceOrder $commerce): bool
    {
        return $this->matchesSupportOrder($support) || $this->matchesCommerceMetadata($commerce);
    }

    private function normalizeProductName(?string $value): ?string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        return strtoupper($trimmed) === self::PRODUCT_NAME ? self::PRODUCT_NAME : $trimmed;
    }
}
