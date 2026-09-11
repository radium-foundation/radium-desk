<?php

namespace Tests\Feature\Pos;

use App\Enums\InventorySerialStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySerial;
use App\Models\InventoryUserBranch;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosSerialMatchTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private InventoryBranch $branch;

    private InventoryProduct $product;

    private InventoryProduct $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);
        InventoryUserBranch::query()->create([
            'user_id' => $this->seller->id,
            'branch_id' => $this->branch->id,
        ]);
        $this->product = InventoryProduct::query()->create([
            'sku' => 'RBMARC11L1',
            'name' => 'Mantra MFS 110 L1',
            'hsn_code' => '84716050',
            'uqc' => 'PCS',
            'gst_percentage' => 18,
            'unit_price' => 2300,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $this->other = InventoryProduct::query()->create([
            'sku' => 'RBAST300L1',
            'name' => 'Mantra MFS 100',
            'hsn_code' => '84716050',
            'uqc' => 'PCS',
            'gst_percentage' => 18,
            'unit_price' => 2100,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $stock = app(InventoryStockService::class);
        $stock->stockInSerialized($this->product, $this->branch, ['2508103320', '2503104063'], $this->seller);
        $stock->stockInSerialized($this->other, $this->branch, ['WRONG-SKU-1'], $this->seller);
    }

    public function test_available_serial_matches_selected_product(): void
    {
        $this->actingAs($this->seller)
            ->getJson(route('pos.serials.match', [
                'branch_id' => $this->branch->id,
                'product_id' => $this->product->id,
                'q' => '2508103320',
            ]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('serial.serial_number', '2508103320');
    }

    public function test_duplicate_scan_is_operator_visible_as_available_until_cart_rejects(): void
    {
        $this->actingAs($this->seller)
            ->getJson(route('pos.serials.match', [
                'branch_id' => $this->branch->id,
                'product_id' => $this->product->id,
                'q' => "2508103320\r",
            ]))
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_wrong_product_serial_is_rejected(): void
    {
        $this->actingAs($this->seller)
            ->getJson(route('pos.serials.match', [
                'branch_id' => $this->branch->id,
                'product_id' => $this->product->id,
                'q' => 'WRONG-SKU-1',
            ]))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('reason', 'wrong_product');
    }

    public function test_unknown_serial_is_rejected(): void
    {
        $this->actingAs($this->seller)
            ->getJson(route('pos.serials.match', [
                'branch_id' => $this->branch->id,
                'product_id' => $this->product->id,
                'q' => 'NO-SUCH-SERIAL',
            ]))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('reason', 'not_found');
    }

    public function test_sold_serial_is_rejected(): void
    {
        InventorySerial::query()->where('serial_number', '2503104063')->update([
            'status' => InventorySerialStatus::Sold,
        ]);

        $this->actingAs($this->seller)
            ->getJson(route('pos.serials.match', [
                'branch_id' => $this->branch->id,
                'product_id' => $this->product->id,
                'q' => '2503104063',
            ]))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('reason', 'sold');
    }

    public function test_reserved_serial_is_rejected(): void
    {
        InventorySerial::query()->where('serial_number', '2508103320')->update([
            'status' => InventorySerialStatus::Reserved,
        ]);

        $this->actingAs($this->seller)
            ->getJson(route('pos.serials.match', [
                'branch_id' => $this->branch->id,
                'product_id' => $this->product->id,
                'q' => '2508103320',
            ]))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('reason', 'reserved');
    }
}
