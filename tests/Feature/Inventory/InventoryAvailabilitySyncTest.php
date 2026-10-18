<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventoryAvailabilitySyncStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Jobs\SyncStorefrontAvailabilityJob;
use App\Models\ChannelSkuMap;
use App\Models\InventoryAvailabilitySyncLog;
use App\Models\InventoryProduct;
use App\Services\Inventory\InventoryAvailabilitySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InventoryAvailabilitySyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'radiumbox.availability_sync.enabled' => true,
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'desk-sync-token',
        ]);
    }

    public function test_sync_posts_quantity_tracked_availability_payload(): void
    {
        Http::fake([
            'radiumbox.test/api/integrations/v1/catalog-availability' => Http::response([
                'data' => [
                    'model_id' => 1753,
                    'desk_product_id' => 1,
                    'available_qty' => 0,
                    'created' => true,
                ],
            ], 201),
        ]);

        $product = InventoryProduct::query()->create([
            'sku' => 'RBTEST001',
            'name' => 'Mapped qty product',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => false,
            'is_active' => true,
        ]);

        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 1753,
            'inventory_product_id' => $product->id,
            'catalog_sku' => 'CATSKU',
            'channel_sku' => '1753',
        ]);

        app(InventoryAvailabilitySyncService::class)->syncProduct($product->id, 1753);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://radiumbox.test/api/integrations/v1/catalog-availability'
                && $request['model_id'] === 1753
                && $request['desk_product_id'] === 1
                && $request['inventory_tracking'] === 'quantity_tracked'
                && $request['payload_version'] === 1;
        });

        $log = InventoryAvailabilitySyncLog::query()->firstOrFail();
        $this->assertSame(InventoryAvailabilitySyncStatus::Synced, $log->status);
    }

    public function test_product_update_dispatches_availability_job_when_enabled(): void
    {
        Queue::fake();

        $product = InventoryProduct::query()->create([
            'sku' => 'RBTEST002',
            'name' => 'Mapped',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_active' => true,
        ]);

        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 946,
            'inventory_product_id' => $product->id,
            'catalog_sku' => 'X',
            'channel_sku' => '946',
        ]);

        app(InventoryAvailabilitySyncService::class)->dispatchIfEligible($product);

        Queue::assertPushed(SyncStorefrontAvailabilityJob::class, function (SyncStorefrontAvailabilityJob $job) use ($product): bool {
            return $job->inventoryProductId === $product->id && $job->radiumboxModelId === 946;
        });
    }
}
