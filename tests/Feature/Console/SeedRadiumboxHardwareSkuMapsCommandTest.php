<?php

namespace Tests\Feature\Console;

use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\InventoryProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RadiumboxHardwareSkuMapTestCatalog;
use Tests\TestCase;

class SeedRadiumboxHardwareSkuMapsCommandTest extends TestCase
{
    use RadiumboxHardwareSkuMapTestCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reloadHardwareFulfilmentConfigFromDisk();
        $this->seedDeskInventoryProductsForConfiguredMaps();
        $this->seedDecoyChannelMapsOnAlternateModelIds();
    }

    public function test_dry_run_previews_verified_maps_without_writing(): void
    {
        $this->artisan('desk:seed-radiumbox-hardware-sku-maps', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(
            0,
            ChannelSkuMap::query()->whereIn('model_id', $this->configuredRadiumboxModelIds())->count(),
        );
    }

    public function test_wm112_maps_to_rbwm112mz_not_keyboard_mouse_combo(): void
    {
        InventoryProduct::query()->create([
            'sku' => 'RBDKM3322W',
            'name' => 'Dell Wireless Keyboard Mouse KM3322W',
            'hsn_code' => '84716040',
            'gst_percentage' => 18,
            'unit_price' => 1500,
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $this->artisan('desk:seed-radiumbox-hardware-sku-maps', ['--apply' => true])
            ->assertSuccessful();

        $map = ChannelSkuMap::query()->where('model_id', 340)->with('product')->firstOrFail();
        $this->assertSame('RBWM112MZ', $map->product->sku);
        $this->assertNotSame('RBDKM3322W', $map->product->sku);
        $this->assertSame('PDLWM112MZ', $map->catalog_sku);
    }

    public function test_apply_creates_maps_idempotently(): void
    {
        $expected = count($this->configuredRadiumboxModelIds());

        $this->artisan('desk:seed-radiumbox-hardware-sku-maps', ['--apply' => true])
            ->assertSuccessful();

        $this->assertSame(
            $expected,
            ChannelSkuMap::query()->whereIn('model_id', $this->configuredRadiumboxModelIds())->count(),
        );

        $this->artisan('desk:seed-radiumbox-hardware-sku-maps', ['--apply' => true])
            ->assertSuccessful();

        $this->assertSame(
            $expected,
            ChannelSkuMap::query()->whereIn('model_id', $this->configuredRadiumboxModelIds())->count(),
        );
    }

    public function test_mfs500_lx_maps_to_rbmfs500fp(): void
    {
        $this->artisan('desk:seed-radiumbox-hardware-sku-maps', ['--apply' => true])
            ->assertSuccessful();

        $map = ChannelSkuMap::query()->where('model_id', 625)->with('product')->firstOrFail();
        $this->assertSame('RBMFS500FP', $map->product->sku);
        $this->assertSame('PMTMFS500Z', $map->catalog_sku);
        $this->assertSame('625', $map->channel_sku);
        $this->assertTrue($map->product->is_serialized);
    }

    public function test_ast300_l1_maps_to_rbast300l1(): void
    {
        $this->artisan('desk:seed-radiumbox-hardware-sku-maps', ['--apply' => true])
            ->assertSuccessful();

        $map = ChannelSkuMap::query()->where('model_id', 1412)->with('product')->firstOrFail();
        $this->assertSame('RBAST300L1', $map->product->sku);
        $this->assertSame('PAAST300L1', $map->catalog_sku);
        $this->assertSame('1412', $map->channel_sku);
        $this->assertTrue($map->product->is_serialized);
    }

    public function test_morpho_usb_cable_maps_to_rbmsousbcb(): void
    {
        $this->artisan('desk:seed-radiumbox-hardware-sku-maps', ['--apply' => true])
            ->assertSuccessful();

        $map = ChannelSkuMap::query()->where('model_id', 1420)->with('product')->firstOrFail();
        $this->assertSame('RBMSOUSBCB', $map->product->sku);
        $this->assertSame('PIDMORUCBL', $map->catalog_sku);
        $this->assertSame('1420', $map->channel_sku);
        $this->assertFalse($map->product->is_serialized);
    }

    public function test_vriddhi_l1_maps_to_rbbpvridl1(): void
    {
        $this->artisan('desk:seed-radiumbox-hardware-sku-maps', ['--apply' => true])
            ->assertSuccessful();

        $map = ChannelSkuMap::query()->where('model_id', 1616)->with('product')->firstOrFail();
        $this->assertSame('RBBPVRIDL1', $map->product->sku);
        $this->assertSame('RBBPVRIDL1', $map->catalog_sku);
        $this->assertSame('1616', $map->channel_sku);
        $this->assertTrue($map->product->is_serialized);
    }

    public function test_sampann_regular_1200_model_1506_maps_to_rbbpsr1200(): void
    {
        $this->artisan('desk:seed-radiumbox-hardware-sku-maps', ['--apply' => true])
            ->assertSuccessful();

        $map = ChannelSkuMap::query()->where('model_id', 1506)->with('product')->firstOrFail();
        $this->assertSame('RBBPSR1200', $map->product->sku);
        $this->assertSame('PBPSAMREG1', $map->catalog_sku);
        $this->assertSame('1506', $map->channel_sku);
        $this->assertTrue($map->product->is_serialized);
    }

    public function test_apply_creates_only_missing_model_1506_when_other_maps_exist(): void
    {
        foreach ($this->configuredRadiumboxModelIds() as $modelId) {
            if ($modelId === 1506) {
                continue;
            }

            $row = collect(config('hardware_fulfilment.radiumbox_com.sku_maps'))
                ->firstWhere('model_id', $modelId);
            $deskSku = strtoupper((string) ($row['desk_sku'] ?? ''));
            $product = InventoryProduct::query()->where('sku', $deskSku)->firstOrFail();

            ChannelSkuMap::query()->create([
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'model_id' => $modelId,
                'inventory_product_id' => $product->id,
                'catalog_sku' => $row['catalog_sku'] ?? null,
                'channel_sku' => (string) ($row['channel_sku'] ?? $modelId),
            ]);
        }

        $this->assertNull(ChannelSkuMap::query()->where('model_id', 1506)->first());

        $this->artisan('desk:seed-radiumbox-hardware-sku-maps', ['--apply' => true])
            ->assertSuccessful();

        $map = ChannelSkuMap::query()->where('model_id', 1506)->with('product')->firstOrFail();
        $this->assertSame('RBBPSR1200', $map->product->sku);
        $this->assertSame(1, ChannelSkuMap::query()->where('model_id', 1506)->count());
    }

    public function test_economy_model_1505_is_not_defined_in_config(): void
    {
        $modelIds = $this->configuredRadiumboxModelIds();

        $this->assertNotContains(1505, $modelIds);
    }

    public function test_mbp401_map_is_created_idempotently(): void
    {
        $this->artisan('desk:seed-radiumbox-hardware-sku-maps', ['--apply' => true])
            ->assertSuccessful();

        $map = ChannelSkuMap::query()->where('model_id', 1753)->with('product')->firstOrFail();
        $this->assertSame('RBSMOOTHED', $map->product->sku);
        $this->assertSame('RBSMBARPRI', $map->catalog_sku);
        $this->assertSame('1753', $map->channel_sku);

        $this->artisan('desk:seed-radiumbox-hardware-sku-maps', ['--apply' => true])
            ->assertSuccessful();

        $this->assertSame(1, ChannelSkuMap::query()->where('model_id', 1753)->count());
    }

    public function test_conflicting_existing_map_fails_closed(): void
    {
        $other = InventoryProduct::query()->create([
            'sku' => 'OTHER-SKU',
            'name' => 'Other',
            'hsn_code' => '85444299',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 1409,
            'inventory_product_id' => $other->id,
            'catalog_sku' => 'CONFLICT',
            'channel_sku' => '1409',
        ]);

        $this->artisan('desk:seed-radiumbox-hardware-sku-maps', ['--apply' => true])
            ->assertFailed();
    }
}
