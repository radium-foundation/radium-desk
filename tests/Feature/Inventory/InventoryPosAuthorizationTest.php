<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InventoryPosAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
    }

    public function test_permission_seeder_can_be_run_twice_without_losing_grants(): void
    {
        $this->seed(RolePermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $hardware = User::factory()->create(['is_active' => true]);
        $hardware->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        $this->assertTrue($admin->can(RolePermissionSeeder::PERMISSION_INVENTORY_OPERATE_ALL_BRANCHES));
        $this->assertTrue($admin->can(RolePermissionSeeder::PERMISSION_POS_CANCEL));
        $this->assertFalse($hardware->can(RolePermissionSeeder::PERMISSION_INVENTORY_OPERATE_ALL_BRANCHES));
        $this->assertFalse($hardware->can(RolePermissionSeeder::PERMISSION_FINANCE_VIEW));
    }

    public function test_admin_inventory_pos_and_finance_grants(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_INVENTORY_VIEW));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_INVENTORY_PRODUCTS_MANAGE));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_INVENTORY_BRANCHES_MANAGE));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_IN));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_TRANSFER));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_ADJUST));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_RESERVE));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_INVENTORY_OPENING_IMPORT));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_INVENTORY_OPERATE_ALL_BRANCHES));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_POS_VIEW));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_POS_SELL));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_POS_CANCEL));
        $this->assertFalse($user->can(RolePermissionSeeder::PERMISSION_POS_PAYMENTS_VERIFY));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_FINANCE_VIEW));

        $this->actingAs($user)->get(route('inventory.adjustments.create'))->assertOk();
        $this->actingAs($user)->get(route('inventory.opening-import.create'))->assertOk();
        $this->actingAs($user)->get(route('finance.dashboard'))->assertOk();
    }

    public function test_hardware_does_not_receive_adjust_cancel_catalog_or_finance(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_INVENTORY_VIEW));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_IN));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_TRANSFER));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_RESERVE));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_POS_VIEW));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_POS_SELL));
        $this->assertFalse($user->can(RolePermissionSeeder::PERMISSION_INVENTORY_PRODUCTS_MANAGE));
        $this->assertFalse($user->can(RolePermissionSeeder::PERMISSION_INVENTORY_BRANCHES_MANAGE));
        $this->assertFalse($user->can(RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_ADJUST));
        $this->assertFalse($user->can(RolePermissionSeeder::PERMISSION_INVENTORY_OPENING_IMPORT));
        $this->assertFalse($user->can(RolePermissionSeeder::PERMISSION_POS_CANCEL));
        $this->assertFalse($user->can(RolePermissionSeeder::PERMISSION_POS_PAYMENTS_VERIFY));
        $this->assertFalse($user->can(RolePermissionSeeder::PERMISSION_INVENTORY_OPERATE_ALL_BRANCHES));
        $this->assertFalse($user->can(RolePermissionSeeder::PERMISSION_FINANCE_VIEW));

        $this->actingAs($user)->get(route('inventory.adjustments.create'))->assertForbidden();
        $this->actingAs($user)->get(route('inventory.opening-import.create'))->assertForbidden();
        $this->actingAs($user)->get(route('inventory.branches.index'))->assertForbidden();
        $this->actingAs($user)->get(route('finance.dashboard'))->assertForbidden();
    }

    public function test_pos_view_without_sell_can_open_history_but_not_the_counter(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(RolePermissionSeeder::PERMISSION_POS_VIEW);

        $this->actingAs($user)->get(route('pos.sales.index'))->assertOk();
        $this->actingAs($user)->get(route('pos.counter.create'))->assertForbidden();
    }

    public function test_agent_cannot_open_invoice_or_stock_pages(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($user)->get(route('inventory.serials.index'))->assertForbidden();
        $this->actingAs($user)->get(route('pos.sales.index'))->assertForbidden();
        $this->actingAs($user)->get(route('inventory.movements.index'))->assertForbidden();
    }
}
