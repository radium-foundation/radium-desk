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
    /**
     * @var array<string, InventoryProduct>
     */
    private array $resolvedProducts = [];

    public function requireProduct(StatutoryInvoiceChannel|string $channel, ?int $modelId): InventoryProduct
    {
        return $this->requireMappedProduct($channel, $modelId);
    }

    public function requireSerializedProductForItem(StatutoryInvoiceChannel|string $channel, CommerceOrderItem $item): InventoryProduct
    {
        $product = $this->requireMappedProduct(
            $channel,
            $item->model_id !== null ? (int) $item->model_id : null,
        );

        if (! $product->is_serialized) {
            throw ValidationException::withMessages([
                'sku_map' => sprintf(
                    'Mapped Desk product %s is quantity-tracked. Use stock allocation instead of serial allocation.',
                    $product->sku,
                ),
            ]);
        }

        return $product;
    }

    public function requireProductForItem(StatutoryInvoiceChannel|string $channel, CommerceOrderItem $item): InventoryProduct
    {
        return $this->requireSerializedProductForItem($channel, $item);
    }

    public function findProduct(StatutoryInvoiceChannel|string $channel, ?int $modelId): ?InventoryProduct
    {
        try {
            return $this->requireMappedProduct($channel, $modelId);
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * Preload channel SKU maps for operational queue scans.
     *
     * @param  list<StatutoryInvoiceChannel|string>  $channels
     */
    public function warmResolvedProductsForChannels(array $channels): void
    {
        $channelValues = [];
        foreach ($channels as $channel) {
            $channelValues[] = $channel instanceof StatutoryInvoiceChannel ? $channel->value : (string) $channel;
        }

        $channelValues = array_values(array_unique(array_filter($channelValues)));
        if ($channelValues === []) {
            return;
        }

        ChannelSkuMap::query()
            ->with('product')
            ->whereIn('channel', $channelValues)
            ->get()
            ->each(function (ChannelSkuMap $map): void {
                if ($map->product === null || ! $map->product->is_active) {
                    return;
                }

                $channelValue = $map->channel instanceof StatutoryInvoiceChannel
                    ? $map->channel->value
                    : (string) $map->channel;

                $this->resolvedProducts[$channelValue.'|'.(int) $map->model_id] = $map->product;
            });
    }

    private function requireMappedProduct(StatutoryInvoiceChannel|string $channel, ?int $modelId): InventoryProduct
    {
        $channelValue = $channel instanceof StatutoryInvoiceChannel ? $channel->value : $channel;

        if ($modelId === null || $modelId < 1) {
            throw ValidationException::withMessages([
                'sku_map' => 'Hardware fulfilment requires a model_id so the Owner SKU map can resolve a Desk product. Name or SKU text is not used.',
            ]);
        }

        $cacheKey = $channelValue.'|'.$modelId;
        if (isset($this->resolvedProducts[$cacheKey])) {
            return $this->resolvedProducts[$cacheKey];
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

        return $this->resolvedProducts[$cacheKey] = $product;
    }
}
