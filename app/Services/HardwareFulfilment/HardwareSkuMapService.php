<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrderItem;
use App\Models\InventoryProduct;
use Illuminate\Validation\ValidationException;

/**
 * Owner-approved Box model_id → Desk inventory product.
 * Matching is (channel, model_id) only. SKU text and catalog names are not keys.
 */
class HardwareSkuMapService
{
    public function requireProduct(StatutoryInvoiceChannel|string $channel, ?int $modelId): InventoryProduct
    {
        $channelValue = $channel instanceof StatutoryInvoiceChannel ? $channel->value : $channel;

        if ($modelId === null || $modelId < 1) {
            throw ValidationException::withMessages([
                'sku_map' => 'Hardware serial allocation requires a model_id so the Owner SKU map can resolve a Desk product. Name or SKU text is not used.',
            ]);
        }

        $map = ChannelSkuMap::query()
            ->with('product')
            ->where('channel', $channelValue)
            ->where('model_id', $modelId)
            ->first();

        if ($map === null || $map->product === null) {
            throw ValidationException::withMessages([
                'sku_map' => sprintf(
                    'No Owner-approved channel_sku_maps row for %s model_id %d. P4 does not invent mappings.',
                    $channelValue,
                    $modelId,
                ),
            ]);
        }

        $product = $map->product;
        if (! $product->is_active) {
            throw ValidationException::withMessages([
                'sku_map' => sprintf('Mapped Desk product %s is inactive.', $product->sku),
            ]);
        }

        if (! $product->is_serialized) {
            throw ValidationException::withMessages([
                'sku_map' => sprintf(
                    'Mapped Desk product %s is not serialized. P4 does not infer non-serialized hardware and P3 requires allocated serials.',
                    $product->sku,
                ),
            ]);
        }

        return $product;
    }

    public function requireProductForItem(StatutoryInvoiceChannel|string $channel, CommerceOrderItem $item): InventoryProduct
    {
        return $this->requireProduct($channel, $item->model_id !== null ? (int) $item->model_id : null);
    }

    public function findProduct(StatutoryInvoiceChannel|string $channel, ?int $modelId): ?InventoryProduct
    {
        try {
            return $this->requireProduct($channel, $modelId);
        } catch (ValidationException) {
            return null;
        }
    }
}
