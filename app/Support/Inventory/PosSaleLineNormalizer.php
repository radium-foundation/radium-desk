<?php

namespace App\Support\Inventory;

use App\Models\InventoryProduct;

/**
 * Merge duplicate serialized POS cart lines that share the same product/variant identity.
 */
final class PosSaleLineNormalizer
{
    /**
     * @param  list<array{
     *     product_id: int,
     *     variant_id?: int|null,
     *     qty: int,
     *     serials?: list<string>|string|null,
     *     unit_price?: float|string|null,
     *     discount?: float|string|null
     * }>  $lines
     * @return list<array{
     *     product_id: int,
     *     variant_id?: int|null,
     *     qty: int,
     *     serials?: list<string>|string|null,
     *     unit_price?: float|string|null,
     *     discount?: float|string|null
     * }>
     */
    public static function normalize(array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        $productIds = [];
        foreach ($lines as $line) {
            if (! empty($line['product_id'])) {
                $productIds[] = (int) $line['product_id'];
            }
        }

        $serializedProductIds = InventoryProduct::query()
            ->whereIn('id', array_values(array_unique($productIds)))
            ->where('is_serialized', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $serializedLookup = array_fill_keys($serializedProductIds, true);

        $merged = [];
        $normalized = [];

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            if ($productId < 1 || ! isset($serializedLookup[$productId])) {
                $normalized[] = $line;

                continue;
            }

            $variantId = ! empty($line['variant_id']) ? (int) $line['variant_id'] : null;
            $key = $productId.':'.($variantId ?? 0);

            if (! isset($merged[$key])) {
                $serials = InventorySerialNumber::parseList($line['serials'] ?? []);
                $merged[$key] = $line;
                $merged[$key]['serials'] = $serials;
                $merged[$key]['qty'] = count($serials);

                continue;
            }

            $existingSerials = InventorySerialNumber::parseList($merged[$key]['serials'] ?? []);
            $incomingSerials = InventorySerialNumber::parseList($line['serials'] ?? []);
            foreach ($incomingSerials as $serial) {
                if (! in_array($serial, $existingSerials, true)) {
                    $existingSerials[] = $serial;
                }
            }

            $merged[$key]['serials'] = $existingSerials;
            $merged[$key]['qty'] = count($existingSerials);
        }

        foreach ($merged as $line) {
            $normalized[] = $line;
        }

        return $normalized;
    }
}
