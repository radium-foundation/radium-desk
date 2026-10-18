<?php

namespace Tests\Feature\Inventory;

use App\Enums\StatutoryInvoiceChannel;
use App\Jobs\SyncStorefrontAvailabilityJob;
use App\Models\ChannelSkuMap;
use App\Models\InventoryProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ChannelSkuMapAvailabilitySyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['radiumbox.availability_sync.enabled' => true]);
    }

    public function test_map_create_inside_transaction_dispatches_only_after_commit(): void
    {
        Queue::fake();

        $product = $this->createProduct('RB-OBS-1');

        DB::transaction(function () use ($product): void {
            ChannelSkuMap::query()->create([
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'model_id' => 501,
                'inventory_product_id' => $product->id,
                'catalog_sku' => 'RB-OBS-1',
                'channel_sku' => '501',
            ]);

            Queue::assertNothingPushed();
        });

        Queue::assertPushed(SyncStorefrontAvailabilityJob::class, function (SyncStorefrontAvailabilityJob $job) use ($product): bool {
            return $job->inventoryProductId === $product->id && $job->radiumboxModelId === 501;
        });
    }

    public function test_rolled_back_map_create_does_not_dispatch(): void
    {
        Queue::fake();

        $product = $this->createProduct('RB-OBS-2');

        try {
            DB::transaction(function () use ($product): void {
                ChannelSkuMap::query()->create([
                    'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                    'model_id' => 502,
                    'inventory_product_id' => $product->id,
                    'catalog_sku' => 'RB-OBS-2',
                    'channel_sku' => '502',
                ]);

                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        Queue::assertNothingPushed();
    }

    public function test_non_radiumbox_channel_map_does_not_dispatch(): void
    {
        Queue::fake();

        $product = $this->createProduct('RB-OBS-3');

        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'model_id' => 503,
            'inventory_product_id' => $product->id,
            'catalog_sku' => 'RB-OBS-3',
            'channel_sku' => '503',
        ]);

        Queue::assertNothingPushed();
    }

    public function test_irrelevant_map_update_does_not_dispatch(): void
    {
        Queue::fake();

        $product = $this->createProduct('RB-OBS-4');

        $map = ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 504,
            'inventory_product_id' => $product->id,
            'catalog_sku' => 'RB-OBS-4',
            'channel_sku' => '504',
        ]);

        Queue::assertPushed(SyncStorefrontAvailabilityJob::class);

        Queue::fake();

        $map->update(['notes' => 'owner note only']);

        Queue::assertNothingPushed();
    }

    public function test_relevant_map_update_dispatches_after_commit(): void
    {
        Queue::fake();

        $product = $this->createProduct('RB-OBS-5');
        $other = $this->createProduct('RB-OBS-6');

        $map = ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 505,
            'inventory_product_id' => $product->id,
            'catalog_sku' => 'RB-OBS-5',
            'channel_sku' => '505',
        ]);

        Queue::assertPushed(SyncStorefrontAvailabilityJob::class, 1);

        Queue::fake();

        DB::transaction(function () use ($map, $other): void {
            $map->update(['inventory_product_id' => $other->id]);

            Queue::assertNothingPushed();
        });

        Queue::assertPushed(SyncStorefrontAvailabilityJob::class, function (SyncStorefrontAvailabilityJob $job) use ($other): bool {
            return $job->inventoryProductId === $other->id;
        });
    }

    private function createProduct(string $sku): InventoryProduct
    {
        return InventoryProduct::query()->create([
            'sku' => $sku,
            'name' => 'Observer test product',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_active' => true,
        ]);
    }
}
