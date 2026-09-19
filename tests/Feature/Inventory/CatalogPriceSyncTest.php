<?php

namespace Tests\Feature\Inventory;

use App\Enums\CatalogPriceSyncStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Jobs\SyncStorefrontCatalogPriceJob;
use App\Models\CatalogPriceSyncLog;
use App\Models\ChannelSkuMap;
use App\Models\InventoryProduct;
use App\Models\User;
use App\Services\Inventory\CatalogPriceSyncService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CatalogPriceSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'radiumbox.catalog_price_sync.enabled' => true,
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'desk-sync-token',
        ]);
    }

    public function test_product_update_dispatches_sync_job_when_mapped_and_enabled(): void
    {
        Queue::fake();

        $product = $this->mappedProduct();
        $user = $this->inventoryManager();

        $this->actingAs($user)
            ->put(route('inventory.products.update', $product), $this->productPayload($product, unitPrice: 10099))
            ->assertRedirect(route('inventory.products.edit', $product));

        Queue::assertPushed(SyncStorefrontCatalogPriceJob::class, function (SyncStorefrontCatalogPriceJob $job) use ($product): bool {
            return $job->inventoryProductId === $product->id && $job->radiumboxModelId === 1753;
        });
    }

    public function test_product_update_does_not_dispatch_when_unmapped(): void
    {
        Queue::fake();

        $product = InventoryProduct::query()->create([
            'sku' => 'UNMAPPED',
            'name' => 'Unmapped Product',
            'gst_percentage' => 18,
            'unit_price' => 1000,
            'is_active' => true,
        ]);
        $user = $this->inventoryManager();

        $this->actingAs($user)
            ->put(route('inventory.products.update', $product), $this->productPayload($product))
            ->assertRedirect();

        Queue::assertNothingPushed();
    }

    public function test_job_sends_inclusive_price_with_deterministic_idempotency_key(): void
    {
        Http::fake([
            'radiumbox.test/api/integrations/v1/catalog-prices' => Http::response([
                'status' => 201,
                'message' => 'Catalog price updated',
                'data' => [
                    'publish_price' => 10099,
                    'selling_price' => 8558.47,
                    'liveprice' => 10099,
                    'gst_percentage' => 18,
                    'effective_at' => now()->toIso8601String(),
                ],
            ], 201),
        ]);

        $product = $this->mappedProduct(['unit_price' => 10099]);
        $expectedKey = CatalogPriceSyncService::idempotencyKey($product->id, 10099, 18);

        app(CatalogPriceSyncService::class)->syncProduct($product->id, 1753);

        Http::assertSent(function ($request) use ($expectedKey): bool {
            return $request->url() === 'https://radiumbox.test/api/integrations/v1/catalog-prices'
                && $request['publish_price'] === 10099.0
                && $request['model_id'] === 1753
                && $request['idempotency_key'] === $expectedKey
                && $request->hasHeader('Idempotency-Key', $expectedKey);
        });

        $log = CatalogPriceSyncLog::query()->firstOrFail();
        $this->assertSame(CatalogPriceSyncStatus::Synced, $log->status);
        $this->assertSame('10099.00', (string) $log->applied_publish_price);
        $this->assertSame(10099, $log->applied_liveprice);
    }

    public function test_idempotency_key_is_deterministic(): void
    {
        $this->assertSame(
            'desk-catalog-price:90:10099.00:18.00',
            CatalogPriceSyncService::idempotencyKey(90, 10099, 18),
        );
    }

    public function test_failure_is_recorded_and_shown_in_edit_ui(): void
    {
        Http::fake([
            'radiumbox.test/api/integrations/v1/catalog-prices' => Http::response([
                'status' => 422,
                'message' => 'Catalog model not found',
            ], 422),
        ]);

        $product = $this->mappedProduct(['unit_price' => 10099]);
        $user = $this->inventoryManager();

        app(CatalogPriceSyncService::class)->syncProduct($product->id, 1753);

        $log = CatalogPriceSyncLog::query()->firstOrFail();
        $this->assertSame(CatalogPriceSyncStatus::Failed, $log->status);
        $this->assertSame('Catalog model not found', $log->error_summary);

        $this->actingAs($user)
            ->get(route('inventory.products.edit', $product))
            ->assertOk()
            ->assertSee('Storefront price not updated')
            ->assertSee('Catalog model not found')
            ->assertSee('Retry storefront sync');
    }

    public function test_retry_route_queues_sync_job(): void
    {
        Queue::fake();

        $product = $this->mappedProduct();
        $user = $this->inventoryManager();

        $this->actingAs($user)
            ->post(route('inventory.products.retry-storefront-sync', $product))
            ->assertRedirect(route('inventory.products.edit', $product));

        Queue::assertPushed(SyncStorefrontCatalogPriceJob::class);
    }

    public function test_existing_product_update_validation_is_unchanged(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'RBTESTSKU',
            'name' => 'Test Product',
            'gst_percentage' => 18,
            'unit_price' => 500,
            'is_active' => true,
        ]);
        $user = $this->inventoryManager();

        $this->actingAs($user)
            ->put(route('inventory.products.update', $product), $this->productPayload($product, unitPrice: 750))
            ->assertRedirect()
            ->assertSessionHas('status', 'Product updated.');

        $this->assertSame('750.00', (string) $product->fresh()->unit_price);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function mappedProduct(array $overrides = []): InventoryProduct
    {
        $product = InventoryProduct::query()->create(array_merge([
            'sku' => 'RBSMOOTHED',
            'name' => 'Smooth Edges MBP-401 Desktop Barcode Printer',
            'gst_percentage' => 18,
            'unit_price' => 9499,
            'is_serialized' => true,
            'is_active' => true,
        ], $overrides));

        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 1753,
            'inventory_product_id' => $product->id,
            'catalog_sku' => 'RBSMBARPRI',
            'channel_sku' => '1753',
        ]);

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(InventoryProduct $product, ?float $unitPrice = null): array
    {
        return [
            'sku' => $product->sku,
            'name' => $product->name,
            'gst_percentage' => $product->gst_percentage,
            'unit_price' => $unitPrice ?? $product->unit_price,
            'is_serialized' => (bool) $product->is_serialized,
            'tracks_batch' => (bool) $product->tracks_batch,
            'is_active' => (bool) $product->is_active,
        ];
    }

    private function inventoryManager(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }
}
