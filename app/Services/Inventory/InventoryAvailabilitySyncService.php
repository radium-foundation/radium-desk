<?php

namespace App\Services\Inventory;

use App\Enums\InventoryAvailabilitySyncStatus;
use App\Enums\InventorySerialStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Jobs\SyncStorefrontAvailabilityJob;
use App\Models\ChannelSkuMap;
use App\Models\InventoryAvailabilitySyncLog;
use App\Models\InventoryProduct;
use App\Services\RadiumBox\RadiumBoxCatalogAvailabilityClient;
use App\Services\RadiumBox\RadiumBoxCatalogAvailabilitySyncException;
use Illuminate\Support\Facades\Log;

class InventoryAvailabilitySyncService
{
    public function __construct(
        private readonly RadiumBoxCatalogAvailabilityClient $client,
    ) {}

    public function dispatchIfEligible(InventoryProduct $product): void
    {
        if (! config('radiumbox.availability_sync.enabled')) {
            return;
        }

        $map = $this->resolveMap($product);
        if ($map === null) {
            return;
        }

        SyncStorefrontAvailabilityJob::dispatch($product->id, (int) $map->model_id);
    }

    public function retry(InventoryProduct $product): void
    {
        if (! config('radiumbox.availability_sync.enabled')) {
            return;
        }

        $map = $this->resolveMap($product);
        if ($map === null) {
            return;
        }

        SyncStorefrontAvailabilityJob::dispatch($product->id, (int) $map->model_id);
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

        [$availableQty, $tracking] = $this->resolveAvailability($product);
        $idempotencyKey = self::idempotencyKey($inventoryProductId, $modelId, $availableQty);

        $log = InventoryAvailabilitySyncLog::query()->create([
            'inventory_product_id' => $inventoryProductId,
            'radiumbox_model_id' => $modelId,
            'available_qty' => $availableQty,
            'inventory_tracking' => $tracking,
            'idempotency_key' => $idempotencyKey,
            'requested_at' => now(),
            'status' => InventoryAvailabilitySyncStatus::Pending,
        ]);

        if (! $this->client->isConfigured()) {
            $this->markFailed($log, 'Storefront availability sync is not configured.');

            return;
        }

        $payload = [
            'channel' => 'radiumbox_com',
            'model_id' => $modelId,
            'desk_product_id' => $inventoryProductId,
            'available_qty' => $availableQty,
            'inventory_tracking' => $tracking,
            'source_as_of' => now()->toIso8601String(),
            'idempotency_key' => $idempotencyKey,
            'payload_version' => 1,
        ];

        try {
            $this->client->syncAvailability($payload);

            $log->update([
                'status' => InventoryAvailabilitySyncStatus::Synced,
                'applied_at' => now(),
                'error_summary' => null,
            ]);

            Log::info('Storefront availability synced.', [
                'inventory_product_id' => $inventoryProductId,
                'radiumbox_model_id' => $modelId,
                'available_qty' => $availableQty,
                'attempt' => $attempt,
            ]);
        } catch (RadiumBoxCatalogAvailabilitySyncException $exception) {
            $this->markFailed($log, $exception->getMessage());

            Log::warning('Storefront availability sync failed.', [
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

    public static function idempotencyKey(int $inventoryProductId, int $modelId, int $availableQty): string
    {
        return sprintf(
            'desk-availability:%d:%d:%d:%s',
            $inventoryProductId,
            $modelId,
            $availableQty,
            now()->format('Y-m-d-H-i'),
        );
    }

    /**
     * @return array{0: int, 1: string}
     */
    public function resolveAvailability(InventoryProduct $product): array
    {
        if ($product->is_serialized) {
            $count = $product->serials()
                ->where('status', InventorySerialStatus::Available->value)
                ->count();

            return [$count, 'serialized'];
        }

        $qty = (int) $product->balances()->sum('available_qty');

        return [$qty, 'quantity_tracked'];
    }

    private function resolveMap(InventoryProduct $product): ?ChannelSkuMap
    {
        return ChannelSkuMap::query()
            ->where('channel', StatutoryInvoiceChannel::RadiumBoxCom)
            ->where('inventory_product_id', $product->id)
            ->first();
    }

    private function markFailed(InventoryAvailabilitySyncLog $log, string $message): void
    {
        $log->update([
            'status' => InventoryAvailabilitySyncStatus::Failed,
            'error_summary' => $message,
        ]);
    }
}
