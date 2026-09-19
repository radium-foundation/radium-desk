<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryProduct;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryProductStorefrontControlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_storefront_controls_persist_on_update(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'RBMFS100L0',
            'name' => 'Mantra MFS 100 L0',
            'gst_percentage' => 18,
            'unit_price' => 4000,
            'is_active' => true,
        ]);

        $user = $this->inventoryManager();

        $this->actingAs($user)
            ->put(route('inventory.products.update', $product), [
                'sku' => 'RBMFS100L0',
                'name' => 'Mantra MFS 100 L0',
                'gst_percentage' => 18,
                'unit_price' => 4000,
                'is_serialized' => 1,
                'is_active' => 1,
                'sell_on_radiumbox' => 1,
                'rd_service_available' => 0,
                'amc_available' => 1,
            ])
            ->assertRedirect(route('inventory.products.edit', $product));

        $fresh = $product->fresh();
        $this->assertTrue($fresh->sell_on_radiumbox);
        $this->assertFalse($fresh->rd_service_available);
        $this->assertTrue($fresh->amc_available);
    }

    public function test_new_products_default_storefront_controls_to_enabled(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'RBTEST001',
            'name' => 'Test Product',
            'gst_percentage' => 18,
            'unit_price' => 500,
            'is_active' => true,
        ]);

        $this->assertTrue($product->sell_on_radiumbox);
        $this->assertTrue($product->rd_service_available);
        $this->assertTrue($product->amc_available);
    }

    public function test_edit_form_shows_storefront_controls(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'RBTEST002',
            'name' => 'Test Product',
            'gst_percentage' => 18,
            'unit_price' => 500,
            'is_active' => true,
        ]);
        $user = $this->inventoryManager();

        $this->actingAs($user)
            ->get(route('inventory.products.edit', $product))
            ->assertOk()
            ->assertSee('RadiumBox Storefront')
            ->assertSee('Sell on RadiumBox')
            ->assertSee('RD Service available')
            ->assertSee('AMC available');
    }

    private function inventoryManager(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }
}
