<?php

namespace App\Jobs;

use App\Infrastructure\Queue\QueueRouting;
use App\Services\Inventory\CatalogPriceSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncStorefrontCatalogPriceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $inventoryProductId,
        public readonly int $radiumboxModelId,
    ) {
        $this->onQueue(QueueRouting::critical());
    }

    public function uniqueId(): string
    {
        return $this->inventoryProductId.':'.$this->radiumboxModelId;
    }

    public function handle(CatalogPriceSyncService $syncService): void
    {
        $syncService->syncProduct(
            inventoryProductId: $this->inventoryProductId,
            modelId: $this->radiumboxModelId,
            attempt: $this->attempts(),
        );
    }

    public function failed(?\Throwable $exception): void
    {
        Log::warning('Storefront catalog price sync exhausted retries.', [
            'inventory_product_id' => $this->inventoryProductId,
            'radiumbox_model_id' => $this->radiumboxModelId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
