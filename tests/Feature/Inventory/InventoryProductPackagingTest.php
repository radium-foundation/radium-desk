<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryProductPackaging;
use App\Models\InventoryUserBranch;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryProductPackagingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $hardware;

    private InventoryBranch $branchA;

    private InventoryBranch $branchB;

    private InventoryProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true, 'name' => 'Pack Admin']);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->hardware = User::factory()->create(['is_active' => true, 'name' => 'Pack Hardware']);
        $this->hardware->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        $this->branchA = InventoryBranch::query()->create([
            'code' => 'PKA',
            'name' => 'Pack Branch A',
            'is_active' => true,
        ]);
        $this->branchB = InventoryBranch::query()->create([
            'code' => 'PKB',
            'name' => 'Pack Branch B',
            'is_active' => true,
        ]);
        InventoryUserBranch::query()->create([
            'user_id' => $this->hardware->id,
            'branch_id' => $this->branchA->id,
        ]);

        $this->product = InventoryProduct::query()->create([
            'sku' => 'RBMFS110L1',
            'name' => 'Mantra MFS110 L1',
            'gst_percentage' => 18,
            'unit_price' => 2549,
            'is_serialized' => true,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_create_verified_packaging(): void
    {
        $this->actingAs($this->admin)
            ->put(route('inventory.stock.packaging.update', $this->product), $this->validPack())
            ->assertRedirect(route('inventory.stock.index'));

        $row = InventoryProductPackaging::query()->where('inventory_product_id', $this->product->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('0.240', $row->gross_weight);
        $this->assertSame('14.00', $row->length);
        $this->assertSame('9.00', $row->breadth);
        $this->assertSame('7.00', $row->height);
        $this->assertSame('kg', $row->weight_unit);
        $this->assertSame('cm', $row->dimension_unit);
        $this->assertSame($this->admin->id, $row->verified_by_user_id);
        $this->assertNotNull($row->verified_at);
        $this->assertTrue($row->product->is($this->product));
    }

    public function test_admin_can_update_packaging_and_reattest(): void
    {
        $this->actingAs($this->admin)
            ->put(route('inventory.stock.packaging.update', $this->product), $this->validPack())
            ->assertRedirect();

        $first = InventoryProductPackaging::query()->where('inventory_product_id', $this->product->id)->firstOrFail();
        $firstVerifiedAt = $first->verified_at;

        $this->travel(2)->minutes();

        $this->actingAs($this->admin)
            ->put(route('inventory.stock.packaging.update', $this->product), $this->validPack([
                'gross_weight' => '0.250',
                'length' => '13',
                'breadth' => '8',
                'height' => '9',
            ]))
            ->assertRedirect();

        $this->assertSame(1, InventoryProductPackaging::query()->count());
        $row = InventoryProductPackaging::query()->firstOrFail();
        $this->assertSame('0.250', $row->gross_weight);
        $this->assertSame('13.00', $row->length);
        $this->assertTrue($row->verified_at->gt($firstVerifiedAt));
        $this->assertSame($this->admin->id, $row->verified_by_user_id);
    }

    public function test_one_packaging_row_per_product(): void
    {
        $this->actingAs($this->admin)
            ->put(route('inventory.stock.packaging.update', $this->product), $this->validPack())
            ->assertRedirect();

        $this->expectException(QueryException::class);
        DB::table('inventory_product_packaging')->insert([
            'inventory_product_id' => $this->product->id,
            'gross_weight' => 1,
            'length' => 1,
            'breadth' => 1,
            'height' => 1,
            'weight_unit' => 'kg',
            'dimension_unit' => 'cm',
            'verified_by_user_id' => $this->admin->id,
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_zero_and_missing_measures_are_rejected(): void
    {
        $this->actingAs($this->admin)
            ->from(route('inventory.stock.packaging.edit', $this->product))
            ->put(route('inventory.stock.packaging.update', $this->product), $this->validPack([
                'gross_weight' => '0',
                'length' => '',
                'breadth' => '-1',
                'height' => '0',
            ]))
            ->assertRedirect(route('inventory.stock.packaging.edit', $this->product))
            ->assertSessionHasErrors(['gross_weight', 'length', 'breadth', 'height']);

        $this->assertDatabaseCount('inventory_product_packaging', 0);
    }

    public function test_non_canonical_units_are_rejected_without_conversion(): void
    {
        $this->actingAs($this->admin)
            ->from(route('inventory.stock.packaging.edit', $this->product))
            ->put(route('inventory.stock.packaging.update', $this->product), $this->validPack([
                'weight_unit' => 'g',
                'dimension_unit' => 'mm',
            ]))
            ->assertRedirect(route('inventory.stock.packaging.edit', $this->product))
            ->assertSessionHasErrors(['weight_unit', 'dimension_unit']);

        $this->assertDatabaseCount('inventory_product_packaging', 0);
    }

    public function test_hardware_team_cannot_verify_packaging_without_dedicated_permission(): void
    {
        $this->assertFalse($this->hardware->can(RolePermissionSeeder::PERMISSION_INVENTORY_PACKAGING_VERIFY));
        $this->assertFalse($this->hardware->can(RolePermissionSeeder::PERMISSION_INVENTORY_PRODUCTS_MANAGE));

        $this->actingAs($this->hardware)
            ->get(route('inventory.stock.packaging.edit', $this->product))
            ->assertForbidden();

        $this->actingAs($this->hardware)
            ->put(route('inventory.stock.packaging.update', $this->product), $this->validPack())
            ->assertForbidden();

        $this->assertDatabaseCount('inventory_product_packaging', 0);
    }

    public function test_packaging_verify_does_not_require_products_manage(): void
    {
        $verifier = User::factory()->create(['is_active' => true, 'name' => 'Pack Verifier']);
        $verifier->givePermissionTo([
            RolePermissionSeeder::PERMISSION_INVENTORY_VIEW,
            RolePermissionSeeder::PERMISSION_INVENTORY_PACKAGING_VERIFY,
        ]);

        $this->assertFalse($verifier->can(RolePermissionSeeder::PERMISSION_INVENTORY_PRODUCTS_MANAGE));

        $this->actingAs($verifier)
            ->get(route('inventory.stock.packaging.edit', $this->product))
            ->assertOk()
            ->assertSee('RBMFS110L1')
            ->assertSee('Mantra MFS110 L1')
            ->assertSee((string) $this->product->id)
            ->assertSee('Not verified');

        $this->actingAs($verifier)
            ->put(route('inventory.stock.packaging.update', $this->product), $this->validPack())
            ->assertRedirect(route('inventory.stock.index'));

        $this->assertDatabaseHas('inventory_product_packaging', [
            'inventory_product_id' => $this->product->id,
            'verified_by_user_id' => $verifier->id,
            'weight_unit' => 'kg',
            'dimension_unit' => 'cm',
        ]);
    }

    public function test_stock_page_shows_unverified_and_verified_pack_without_guessing(): void
    {
        app(InventoryStockService::class)->stockInSerialized(
            $this->product,
            $this->branchA,
            ['PK-A-1'],
            $this->admin,
        );
        app(InventoryStockService::class)->stockInSerialized(
            $this->product,
            $this->branchB,
            ['PK-B-1'],
            $this->admin,
        );

        $this->actingAs($this->admin)
            ->get(route('inventory.stock.index'))
            ->assertOk()
            ->assertSee('Stock by branch')
            ->assertSee('Product ID')
            ->assertSee((string) $this->product->id)
            ->assertSee('RBMFS110L1')
            ->assertSee('Mantra MFS110 L1')
            ->assertSee('Pack Branch A')
            ->assertSee('Pack Branch B')
            ->assertSee('Not verified')
            ->assertSee('Record pack')
            ->assertDontSee('0.240');

        $this->actingAs($this->admin)
            ->put(route('inventory.stock.packaging.update', $this->product), $this->validPack())
            ->assertRedirect();

        $html = $this->actingAs($this->admin)
            ->get(route('inventory.stock.index'))
            ->assertOk()
            ->assertSee('Verified')
            ->assertSee('0.240')
            ->assertSee('14.00 × 9.00 × 7.00')
            ->assertSee('kg / cm')
            ->assertSee('Edit pack')
            ->assertDontSee('Not verified')
            ->getContent();

        $this->assertSame(2, substr_count($html, '14.00 × 9.00 × 7.00'));
    }

    public function test_hardware_stock_page_hides_record_pack_and_keeps_qty_filters(): void
    {
        app(InventoryStockService::class)->stockInSerialized(
            $this->product,
            $this->branchA,
            ['PK-HW-1'],
            $this->admin,
        );
        app(InventoryStockService::class)->stockInSerialized(
            $this->product,
            $this->branchB,
            ['PK-HW-2'],
            $this->admin,
        );

        $this->actingAs($this->hardware)
            ->get(route('inventory.stock.index', ['branch_id' => $this->branchA->id]))
            ->assertOk()
            ->assertSee('RBMFS110L1')
            ->assertSee('Pack Branch A')
            ->assertDontSee('Pack Branch B')
            ->assertSee('Not verified')
            ->assertDontSee('Record pack')
            ->assertDontSee('Edit pack');
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validPack(array $overrides = []): array
    {
        return array_merge([
            'gross_weight' => '0.240',
            'length' => '14',
            'breadth' => '9',
            'height' => '7',
            'weight_unit' => 'kg',
            'dimension_unit' => 'cm',
        ], $overrides);
    }
}
