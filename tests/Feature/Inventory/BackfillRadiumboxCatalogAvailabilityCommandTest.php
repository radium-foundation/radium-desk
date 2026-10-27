<?php

namespace Tests\Feature\Inventory;

use App\Enums\StatutoryInvoiceChannel;
use App\Jobs\SyncStorefrontAvailabilityJob;
use App\Models\ChannelSkuMap;
use App\Models\InventoryProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackfillRadiumboxCatalogAvailabilityCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['radiumbox.availability_sync.enabled' => false]);
    }

    public function test_command_is_no_op_when_sync_flag_disabled(): void
    {
        Queue::fake();

        $this->seedMappedProduct('RB-BF-1', 101);

        Artisan::call('radiumbox:backfill-catalog-availability');

        Queue::assertNothingPushed();
    }

    public function test_dry_run_does_not_dispatch_jobs_even_when_enabled(): void
    {
        $this->seedMappedProduct('RB-BF-2', 102);

        Queue::fake();
        config(['radiumbox.availability_sync.enabled' => true]);

        Artisan::call('radiumbox:backfill-catalog-availability', ['--dry-run' => true]);

        Queue::assertNothingPushed();
    }

    public function test_enabled_backfill_respects_limit(): void
    {
        $first = $this->seedMappedProduct('RB-BF-3', 201);
        $this->seedMappedProduct('RB-BF-4', 202);

        Queue::fake();
        config(['radiumbox.availability_sync.enabled' => true]);

        Artisan::call('radiumbox:backfill-catalog-availability', ['--limit' => 1]);

        Queue::assertPushed(SyncStorefrontAvailabilityJob::class, 1);
        Queue::assertPushed(SyncStorefrontAvailabilityJob::class, function (SyncStorefrontAvailabilityJob $job) use ($first): bool {
            return $job->inventoryProductId === $first->id && $job->radiumboxModelId === 201;
        });
    }

    public function test_backfill_skips_inactive_products_and_other_channels(): void
    {
        $inactive = $this->seedMappedProduct('RB-BF-OFF', 401);
        $inactive->update(['is_active' => false]);

        $other = InventoryProduct::query()->create([
            'sku' => 'RB-BF-OTHER',
            'name' => 'Other channel',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_active' => true,
        ]);
        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'model_id' => 402,
            'inventory_product_id' => $other->id,
            'catalog_sku' => 'RB-BF-OTHER',
            'channel_sku' => '402',
        ]);

        $active = $this->seedMappedProduct('RB-BF-ON', 403);

        Queue::fake();
        config(['radiumbox.availability_sync.enabled' => true]);

        Artisan::call('radiumbox:backfill-catalog-availability');

        Queue::assertPushed(SyncStorefrontAvailabilityJob::class, 1);
        Queue::assertPushed(SyncStorefrontAvailabilityJob::class, function (SyncStorefrontAvailabilityJob $job) use ($active): bool {
            return $job->inventoryProductId === $active->id && $job->radiumboxModelId === 403;
        });
    }

    public function test_enabled_backfill_dispatches_for_active_mapped_products(): void
    {
        $product = $this->seedMappedProduct('RB-BF-5', 303);

        Queue::fake();
        config(['radiumbox.availability_sync.enabled' => true]);

        Artisan::call('radiumbox:backfill-catalog-availability');

        Queue::assertPushed(SyncStorefrontAvailabilityJob::class, function (SyncStorefrontAvailabilityJob $job) use ($product): bool {
            return $job->inventoryProductId === $product->id && $job->radiumboxModelId === 303;
        });
    }

    private function seedMappedProduct(string $sku, int $modelId): InventoryProduct
    {
        $product = InventoryProduct::query()->create([
            'sku' => $sku,
            'name' => 'Backfill test',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_active' => true,
        ]);

        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => $modelId,
            'inventory_product_id' => $product->id,
            'catalog_sku' => $sku,
            'channel_sku' => (string) $modelId,
        ]);

        return $product;
    }
}
