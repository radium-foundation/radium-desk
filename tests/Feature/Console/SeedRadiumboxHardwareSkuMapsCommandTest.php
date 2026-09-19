<?php

namespace Tests\Feature\Console;

use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\InventoryProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedRadiumboxHardwareSkuMapsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            ['RBHYP2003T', 347],
            ['RBBIOC600C', 1749],
            ['RBMFSTYPEC', 1409],
            ['RBMFSUSBCB', 1410],
            ['RBWM112MZ', 340],
            ['RBSMOOTHED', 1753],
        ] as [$sku, $modelId]) {
            $product = InventoryProduct::query()->create([
                'sku' => $sku,
                'name' => $sku,
                'hsn_code' => '85444299',
                'gst_percentage' => 18,
                'unit_price' => 100,
                'is_serialized' => in_array($sku, ['RBHYP2003T', 'RBBIOC600C'], true),
                'is_active' => true,
            ]);
            ChannelSkuMap::query()->create([
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'model_id' => 9990 + $modelId,
                'inventory_product_id' => $product->id,
                'catalog_sku' => 'EXISTING-'.$modelId,
                'channel_sku' => '9990',
            ]);
        }
    }

    public function test_dry_run_previews_verified_maps_without_writing(): void
    {
        $this->artisan('desk:seed-radiumbox-hardware-sku-maps', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(0, ChannelSkuMap::query()->whereIn('model_id', [347, 1749, 1409, 1410, 340, 1753])->count());
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
        $this->artisan('desk:seed-radiumbox-hardware-sku-maps', ['--apply' => true])
            ->assertSuccessful();

        $this->assertSame(6, ChannelSkuMap::query()->whereIn('model_id', [347, 1749, 1409, 1410, 340, 1753])->count());

        $this->artisan('desk:seed-radiumbox-hardware-sku-maps', ['--apply' => true])
            ->assertSuccessful();

        $this->assertSame(6, ChannelSkuMap::query()->whereIn('model_id', [347, 1749, 1409, 1410, 340, 1753])->count());
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
