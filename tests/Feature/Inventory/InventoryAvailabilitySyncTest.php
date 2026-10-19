<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventoryAdjustmentReason;
use App\Enums\InventoryAvailabilitySyncStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Jobs\SyncStorefrontAvailabilityJob;
use App\Models\ChannelSkuMap;
use App\Models\InventoryAvailabilitySyncLog;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\User;
use App\Services\Inventory\InventoryAvailabilitySyncService;
use App\Services\Inventory\InventoryStockService;
use App\Services\RadiumBox\RadiumBoxCatalogAvailabilitySyncException;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_quantity_adjustment_dispatches_availability_job_when_enabled(): void
    {
        Queue::fake();

        $this->seed(RolePermissionSeeder::class);
        $actor = User::factory()->create(['is_active' => true]);
        $branch = InventoryBranch::query()->create([
            'code' => 'HQ',
            'name' => 'Head Office',
            'is_active' => true,
        ]);

        $product = InventoryProduct::query()->create([
            'sku' => 'RBTEST003',
            'name' => 'Qty mapped',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => false,
            'is_active' => true,
        ]);

        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 1753,
            'inventory_product_id' => $product->id,
            'catalog_sku' => 'CAT',
            'channel_sku' => '1753',
        ]);

        app(InventoryStockService::class)->adjustQuantity(
            $product,
            $branch,
            2,
            InventoryAdjustmentReason::Found,
            $actor,
        );

        Queue::assertPushed(SyncStorefrontAvailabilityJob::class, function (SyncStorefrontAvailabilityJob $job) use ($product): bool {
            return $job->inventoryProductId === $product->id && $job->radiumboxModelId === 1753;
        });
    }

    public function test_repeat_sync_keeps_quantity_and_records_each_delivery(): void
    {
        Queue::fake();
        Http::fake([
            'radiumbox.test/api/integrations/v1/catalog-availability' => Http::response(['data' => ['created' => true]], 201),
        ]);

        $product = InventoryProduct::query()->create([
            'sku' => 'RBTEST-REPEAT',
            'name' => 'Repeat',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 945,
            'inventory_product_id' => $product->id,
            'catalog_sku' => 'MFS100',
            'channel_sku' => '945',
        ]);

        $service = app(InventoryAvailabilitySyncService::class);
        Carbon::setTestNow('2026-10-19 08:02:00');
        $service->syncProduct($product->id, 945);
        Carbon::setTestNow('2026-10-19 08:12:00');
        $service->syncProduct($product->id, 945);

        $logs = InventoryAvailabilitySyncLog::query()->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertTrue($logs->every(fn (InventoryAvailabilitySyncLog $log): bool => $log->status === InventoryAvailabilitySyncStatus::Synced));
        $this->assertSame((int) $logs[0]->available_qty, (int) $logs[1]->available_qty);
        $this->assertNotSame($logs[0]->idempotency_key, $logs[1]->idempotency_key);
        Carbon::setTestNow();
    }

    public function test_failed_delivery_is_visible_and_not_marked_synced(): void
    {
        Queue::fake();
        Http::fake([
            'radiumbox.test/api/integrations/v1/catalog-availability' => Http::response(['message' => 'unavailable'], 503),
        ]);

        $product = InventoryProduct::query()->create([
            'sku' => 'RBTEST-FAIL',
            'name' => 'Fail',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 946,
            'inventory_product_id' => $product->id,
            'catalog_sku' => 'MFS110',
            'channel_sku' => '946',
        ]);

        try {
            app(InventoryAvailabilitySyncService::class)->syncProduct($product->id, 946);
            $this->fail('Retriable delivery failure should surface to the queue.');
        } catch (RadiumBoxCatalogAvailabilitySyncException) {
            $log = InventoryAvailabilitySyncLog::query()->firstOrFail();
            $this->assertSame(InventoryAvailabilitySyncStatus::Failed, $log->status);
            $this->assertNotNull($log->error_summary);
        }
    }

    public function test_channel_map_create_dispatches_availability_job_when_enabled(): void
    {
        Queue::fake();

        $product = InventoryProduct::query()->create([
            'sku' => 'RBTEST004',
            'name' => 'New map',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_active' => true,
        ]);

        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 99,
            'inventory_product_id' => $product->id,
            'catalog_sku' => 'Z',
            'channel_sku' => '99',
        ]);

        Queue::assertPushed(SyncStorefrontAvailabilityJob::class, function (SyncStorefrontAvailabilityJob $job) use ($product): bool {
            return $job->inventoryProductId === $product->id && $job->radiumboxModelId === 99;
        });
    }
}
