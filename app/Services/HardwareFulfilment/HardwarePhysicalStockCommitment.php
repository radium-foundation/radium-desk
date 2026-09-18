<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareFulfilmentSerialStatus;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\InventoryProduct;

/**
 * Determines whether hardware lines require serial allocation or quantity stock,
 * and whether fulfilment stock is committed for each physical line.
 */
final class HardwarePhysicalStockCommitment
{
    public function __construct(
        private readonly HardwareSkuMapService $skuMap,
    ) {}

    public function mappedProduct(CommerceOrder $order, CommerceOrderItem $item): ?InventoryProduct
    {
        if ($item->model_id === null || (int) $item->model_id < 1) {
            return null;
        }

        return $this->skuMap->findProduct($order->channel, (int) $item->model_id);
    }

    public function requiresSerialAllocation(CommerceOrder $order, CommerceOrderItem $item): bool
    {
        $product = $this->mappedProduct($order, $item);

        return $product !== null && $product->is_serialized;
    }

    public function isQuantityOnlyOrder(CommerceOrder $order): bool
    {
        $order->loadMissing('items');
        $hasPhysical = false;

        foreach ($order->items as $item) {
            if (! HardwareFulfilmentEligibility::isPhysicalCommerceItem($item)) {
                continue;
            }

            $hasPhysical = true;
            $product = $this->mappedProduct($order, $item);
            if ($product === null || $product->is_serialized) {
                return false;
            }
        }

        return $hasPhysical;
    }

    public function isStockCommitted(HardwareFulfilment $fulfilment, CommerceOrder $order): bool
    {
        $order->loadMissing('items');
        $fulfilment->loadMissing('serials');

        $allocatedByItem = $fulfilment->serials
            ->where('status', HardwareFulfilmentSerialStatus::Allocated)
            ->groupBy(fn ($row) => (int) $row->commerce_order_item_id);

        $physicalLines = 0;

        foreach ($order->items as $item) {
            if (! HardwareFulfilmentEligibility::isPhysicalCommerceItem($item)) {
                continue;
            }

            $physicalLines++;
            $product = $this->mappedProduct($order, $item);
            if ($product === null) {
                return false;
            }

            $requiredQty = (int) $item->qty;
            if ($product->is_serialized) {
                $allocated = $allocatedByItem->get((int) $item->id, collect())->count();
                if ($allocated !== $requiredQty) {
                    return false;
                }

                continue;
            }

            if ($this->quantityCommittedQty($fulfilment, (int) $item->id) !== $requiredQty) {
                return false;
            }
        }

        return $physicalLines > 0;
    }

    public function quantityCommittedQty(HardwareFulfilment $fulfilment, int $commerceOrderItemId): int
    {
        $meta = $fulfilment->metadata ?? [];
        $rows = $meta['quantity_stock'] ?? [];

        return (int) ($rows[(string) $commerceOrderItemId] ?? 0);
    }

    /**
     * @param  array<string, int>  $quantitiesByItemId
     */
    public function mergeQuantityCommitMetadata(HardwareFulfilment $fulfilment, array $quantitiesByItemId): array
    {
        $meta = $fulfilment->metadata ?? [];
        $rows = $meta['quantity_stock'] ?? [];

        foreach ($quantitiesByItemId as $itemId => $qty) {
            $rows[(string) $itemId] = (int) $qty;
        }

        $meta['quantity_stock'] = $rows;
        $meta['quantity_stock_committed_at'] = now()->toIso8601String();

        return $meta;
    }
}
