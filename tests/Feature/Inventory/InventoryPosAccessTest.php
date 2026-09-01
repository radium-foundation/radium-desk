<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryPosAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_can_open_inventory_and_pos_pages(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($user)->get(route('inventory.stock.index'))->assertOk()->assertSee('Stock by branch');
        $this->actingAs($user)->get(route('inventory.products.index'))->assertOk();
        $this->actingAs($user)->get(route('inventory.branches.index'))->assertOk();
        $this->actingAs($user)->get(route('pos.counter.create'))->assertOk()->assertSee('Retail counter');
        $this->actingAs($user)->get(route('pos.sales.index'))->assertOk();
    }

    public function test_agent_cannot_access_inventory_or_pos(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($user)->get(route('inventory.stock.index'))->assertForbidden();
        $this->actingAs($user)->get(route('pos.counter.create'))->assertForbidden();
    }

    public function test_hardware_team_can_stock_in_and_sell_but_not_manage_products(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_INVENTORY_VIEW));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_POS_SELL));
        $this->assertFalse($user->can(RolePermissionSeeder::PERMISSION_INVENTORY_PRODUCTS_MANAGE));
        $this->assertFalse($user->can(RolePermissionSeeder::PERMISSION_POS_CANCEL));

        $this->actingAs($user)->get(route('inventory.stock.index'))->assertOk();
        $this->actingAs($user)->get(route('inventory.products.index'))->assertForbidden();
        $this->actingAs($user)->get(route('pos.counter.create'))->assertOk();
    }

    public function test_admin_can_complete_pos_sale_through_http(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $branch = InventoryBranch::query()->create(['code' => 'HQ', 'name' => 'HQ', 'is_active' => true]);
        $product = InventoryProduct::query()->create([
            'sku' => 'MFS110-HTTP',
            'name' => 'MFS110',
            'gst_percentage' => 18,
            'unit_price' => 1000,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($product, $branch, ['HTTP-1'], $user);

        $this->actingAs($user)
            ->post(route('pos.counter.store'), [
                'branch_id' => $branch->id,
                'customer_name' => 'Walk-in',
                'customer_phone' => '9000000001',
                'payment_method' => 'Cash',
                'discount' => 0,
                'lines' => [[
                    'product_id' => $product->id,
                    'qty' => 1,
                    'serials' => 'HTTP-1',
                ]],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('inventory_sales', [
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('inventory_serials', [
            'serial_number' => 'HTTP-1',
            'status' => 'sold',
        ]);
    }
}
