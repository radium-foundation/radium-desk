<?php

namespace App\Services\RadiumBox;

/**
 * Request-scoped cache for RadiumBox API responses.
 *
 * Prevents duplicate lookups during the same HTTP request. A persistent cache
 * layer can wrap or replace this class in a future phase.
 */
class RadiumBoxRequestCache
{
    /** @var array<string, RadiumBoxOrderEnrichment|null> */
    private array $orderEnrichments = [];

    public function rememberOrderEnrichment(string $orderId, callable $resolver): ?RadiumBoxOrderEnrichment
    {
        if (array_key_exists($orderId, $this->orderEnrichments)) {
            return $this->orderEnrichments[$orderId];
        }

        $this->orderEnrichments[$orderId] = $resolver();

        return $this->orderEnrichments[$orderId];
    }
}
