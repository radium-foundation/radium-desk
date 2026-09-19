<?php

namespace App\Services\Inventory;

use App\Enums\CatalogPriceSyncStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Jobs\SyncStorefrontCatalogPriceJob;
use App\Models\CatalogPriceSyncLog;
use App\Models\ChannelSkuMap;
use App\Models\InventoryProduct;
use App\Services\RadiumBox\RadiumBoxCatalogPriceClient;
use App\Services\RadiumBox\RadiumBoxCatalogPriceSyncException;
use Illuminate\Support\Facades\Log;

class CatalogPriceSyncService
{
    public function __construct(
        private readonly RadiumBoxCatalogPriceClient $client,
    ) {}

    public function dispatchIfEligible(InventoryProduct $product): void
    {
        if (! config('radiumbox.catalog_price_sync.enabled')) {
            return;
        }

        $map = $this->resolveMap($product);
        if ($map === null) {
            return;
        }

        SyncStorefrontCatalogPriceJob::dispatch($product->id, (int) $map->model_id);
    }

    public function retry(InventoryProduct $product): void
    {
        if (! config('radiumbox.catalog_price_sync.enabled')) {
            return;
        }

        $map = $this->resolveMap($product);
        if ($map === null) {
            return;
        }

        SyncStorefrontCatalogPriceJob::dispatch($product->id, (int) $map->model_id);
    }

    public function syncProduct(int $inventoryProductId, int $modelId, int $attempt = 1): void
    {
        $product = InventoryProduct::query()->find($inventoryProductId);
        if ($product === null) {
            return;
        }

        $map = ChannelSkuMap::query()
            ->where('channel', StatutoryInvoiceChannel::RadiumBoxCom)
            ->where('inventory_product_id', $inventoryProductId)
            ->where('model_id', $modelId)
            ->first();

        if ($map === null) {
            return;
        }

        $publishPrice = round((float) $product->unit_price, 2);
        $gstPercentage = round((float) $product->gst_percentage, 2);
        $idempotencyKey = self::idempotencyKey($inventoryProductId, $publishPrice, $gstPercentage);

        $log = CatalogPriceSyncLog::query()->create([
            'inventory_product_id' => $inventoryProductId,
            'radiumbox_model_id' => $modelId,
            'requested_publish_price' => $publishPrice,
            'requested_gst_percentage' => $gstPercentage,
            'idempotency_key' => $idempotencyKey,
            'requested_at' => now(),
            'status' => CatalogPriceSyncStatus::Pending,
        ]);

        if (! $this->client->isConfigured()) {
            $this->markFailed($log, 'Storefront catalog price sync is not configured.');

            return;
        }

        try {
            $result = $this->client->syncCatalogPrice(
                deskProductId: $inventoryProductId,
                modelId: $modelId,
                publishPrice: $publishPrice,
                gstPercentage: $gstPercentage,
                idempotencyKey: $idempotencyKey,
                storefrontSellable: (bool) $product->sell_on_radiumbox,
                rdServiceAvailable: (bool) $product->rd_service_available,
                amcAvailable: (bool) $product->amc_available,
            );

            $log->update([
                'status' => CatalogPriceSyncStatus::Synced,
                'applied_at' => now(),
                'applied_publish_price' => $result['publish_price'],
                'applied_selling_price' => $result['selling_price'],
                'applied_liveprice' => $result['liveprice'],
                'applied_gst_percentage' => $result['gst_percentage'],
                'error_summary' => null,
            ]);

            Log::info('Storefront catalog price synced.', [
                'inventory_product_id' => $inventoryProductId,
                'radiumbox_model_id' => $modelId,
                'publish_price' => $result['publish_price'],
                'liveprice' => $result['liveprice'],
                'attempt' => $attempt,
            ]);
        } catch (RadiumBoxCatalogPriceSyncException $exception) {
            $this->markFailed($log, $exception->getMessage());

            Log::warning('Storefront catalog price sync failed.', [
                'inventory_product_id' => $inventoryProductId,
                'radiumbox_model_id' => $modelId,
                'attempt' => $attempt,
                'retriable' => $exception->retriable,
                'error' => $exception->getMessage(),
            ]);

            if ($exception->retriable) {
                throw $exception;
            }
        }
    }

    public static function idempotencyKey(int $inventoryProductId, float $publishPrice, float $gstPercentage): string
    {
        return sprintf(
            'desk-catalog-price:%d:%s:%s',
            $inventoryProductId,
            number_format($publishPrice, 2, '.', ''),
            number_format($gstPercentage, 2, '.', ''),
        );
    }

    public function latestLogFor(InventoryProduct $product): ?CatalogPriceSyncLog
    {
        return CatalogPriceSyncLog::query()
            ->where('inventory_product_id', $product->id)
            ->latest('id')
            ->first();
    }

    private function resolveMap(InventoryProduct $product): ?ChannelSkuMap
    {
        return ChannelSkuMap::query()
            ->where('channel', StatutoryInvoiceChannel::RadiumBoxCom)
            ->where('inventory_product_id', $product->id)
            ->first();
    }

    private function markFailed(CatalogPriceSyncLog $log, string $message): void
    {
        $log->update([
            'status' => CatalogPriceSyncStatus::Failed,
            'error_summary' => $message,
        ]);
    }
}
