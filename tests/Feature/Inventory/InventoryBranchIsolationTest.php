<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySerial;
use App\Models\InventoryUserBranch;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryBranchIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
    }

    public function test_hardware_assigned_to_branch_a_cannot_sell_branch_b_stock(): void
    {
        [$user, $branchA, $branchB, $product] = $this->hardwareWithTwoBranches();
        app(InventoryStockService::class)->stockInSerialized($product, $branchB, ['ISO-B-1'], $user);

        $this->actingAs($user)
            ->post(route('pos.counter.store'), [
                'branch_id' => $branchB->id,
                'customer_name' => 'Walk-in',
                'customer_phone' => '9000000099',
                'payment_method' => 'Cash',
                'lines' => [[
                    'product_id' => $product->id,
                    'qty' => 1,
                    'serials' => 'ISO-B-1',
                ]],
            ])
            ->assertSessionHasErrors('branch_id');

        $this->assertDatabaseHas('inventory_serials', [
            'serial_number' => 'ISO-B-1',
            'status' => 'available',
            'branch_id' => $branchB->id,
        ]);
    }

    public function test_hardware_assigned_to_branch_a_cannot_transfer_from_branch_b(): void
    {
        [$user, $branchA, $branchB, $product] = $this->hardwareWithTwoBranches();
        app(InventoryStockService::class)->stockInSerialized($product, $branchB, ['ISO-TR-1'], $user);

        $this->actingAs($user)
            ->post(route('inventory.transfers.store'), [
                'from_branch_id' => $branchB->id,
                'to_branch_id' => $branchA->id,
                'mode' => 'serial',
                'serials' => 'ISO-TR-1',
            ])
            ->assertSessionHasErrors('from_branch_id');

        $this->assertSame($branchB->id, InventorySerial::query()->where('serial_number', 'ISO-TR-1')->value('branch_id'));
    }

    public function test_hardware_stock_list_hides_other_branch_balances(): void
    {
        [$user, $branchA, $branchB, $product] = $this->hardwareWithTwoBranches();
        $stock = app(InventoryStockService::class);
        $stock->stockInSerialized($product, $branchA, ['ISO-A-1'], $user);
        $stock->stockInSerialized($product, $branchB, ['ISO-B-2'], $user);

        $this->actingAs($user)
            ->get(route('inventory.stock.index'))
            ->assertOk()
            ->assertSee($branchA->name)
            ->assertDontSee($branchB->name);
    }

    public function test_unassigned_hardware_is_told_to_request_a_branch(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        InventoryBranch::query()->create(['code' => 'HQ', 'name' => 'Head Office', 'is_active' => true]);

        $this->actingAs($user)
            ->get(route('pos.counter.create'))
            ->assertOk()
            ->assertSee('You are not assigned to a branch');
    }

    public function test_admin_with_all_branch_access_can_sell_without_assignment(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $branch = InventoryBranch::query()->create(['code' => 'HQ', 'name' => 'Head Office', 'is_active' => true]);
        $product = InventoryProduct::query()->create([
            'sku' => 'ISO-ADMIN',
            'name' => 'Admin sale',
            'gst_percentage' => 18,
            'unit_price' => 1000,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($product, $branch, ['ISO-ADMIN-1'], $user);

        $this->actingAs($user)
            ->post(route('pos.counter.store'), [
                'branch_id' => $branch->id,
                'customer_name' => 'Walk-in',
                'customer_phone' => '9000000002',
                'payment_method' => 'Cash',
                'idempotency_key' => 'admin-http-1',
                'lines' => [[
                    'product_id' => $product->id,
                    'qty' => 1,
                    'serials' => 'ISO-ADMIN-1',
                ]],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('inventory_serials', [
            'serial_number' => 'ISO-ADMIN-1',
            'status' => 'sold',
        ]);
    }

    public function test_product_search_is_limited_to_the_operating_branch_stock(): void
    {
        [$user, $branchA, $branchB, $product] = $this->hardwareWithTwoBranches();
        app(InventoryStockService::class)->stockInSerialized($product, $branchA, ['ISO-SRCH-A'], $user);
        app(InventoryStockService::class)->stockInSerialized($product, $branchB, ['ISO-SRCH-B'], $user);

        $this->actingAs($user)
            ->getJson(route('pos.products.search', ['branch_id' => $branchA->id, 'q' => 'ISO']))
            ->assertOk()
            ->assertJsonPath('products.0.available_qty', 1);

        $this->actingAs($user)
            ->getJson(route('pos.serials.search', ['branch_id' => $branchA->id, 'q' => 'ISO-SRCH']))
            ->assertOk()
            ->assertJsonPath('serials.0.serial_number', 'ISO-SRCH-A');

        $this->actingAs($user)
            ->getJson(route('pos.serials.search', ['branch_id' => $branchB->id, 'q' => 'ISO-SRCH']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('branch_id');
    }

    public function test_hardware_cannot_cancel_a_sale(): void
    {
        [$user, $branchA, $branchB, $product] = $this->hardwareWithTwoBranches();
        app(InventoryStockService::class)->stockInSerialized($product, $branchA, ['ISO-CAN-1'], $user);
        $sale = app(PosSaleService::class)->completeSale(
            branch: $branchA,
            customer: ['name' => 'Walk-in', 'phone' => '9000000033'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['ISO-CAN-1'],
            ]],
            paymentMethod: 'Cash',
            actor: $user,
        );

        $this->actingAs($user)
            ->post(route('pos.sales.cancel', $sale), ['reason' => 'Customer changed mind'])
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: InventoryBranch, 2: InventoryBranch, 3: InventoryProduct}
     */
    private function hardwareWithTwoBranches(): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        $branchA = InventoryBranch::query()->create(['code' => 'BLR', 'name' => 'Bengaluru counter', 'is_active' => true]);
        $branchB = InventoryBranch::query()->create(['code' => 'DEL', 'name' => 'Delhi warehouse', 'is_active' => true]);
        InventoryUserBranch::query()->create([
            'user_id' => $user->id,
            'branch_id' => $branchA->id,
        ]);
        $product = InventoryProduct::query()->create([
            'sku' => 'ISO-MFS',
            'name' => 'Isolation scanner',
            'gst_percentage' => 18,
            'unit_price' => 1200,
            'is_serialized' => true,
            'is_active' => true,
        ]);

        return [$user, $branchA, $branchB, $product];
    }
}
