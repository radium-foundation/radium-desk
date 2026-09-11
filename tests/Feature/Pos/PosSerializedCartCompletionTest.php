<?php

namespace Tests\Feature\Pos;

use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySaleLine;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PosSerializedCartCompletionTest extends TestCase
{
    use RefreshDatabase;

    private PosSaleService $sales;

    private InventoryStockService $stock;

    private User $actor;

    private InventoryBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->sales = app(PosSaleService::class);
        $this->stock = app(InventoryStockService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->branch = InventoryBranch::query()->create([
            'code' => 'HQ',
            'name' => 'Head Office',
            'is_active' => true,
        ]);
    }

    public function test_one_line_with_two_serials_completes_with_qty_two_and_both_serials(): void
    {
        $product = $this->serializedProduct('RBMBAS50L1', 'Mantra L1 MBAS 50 AEBAS Tablet');
        $this->stock->stockInSerialized($product, $this->branch, ['2510106871', '2510106881'], $this->actor);

        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Walk-in', 'phone' => '9000002001'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 2,
                'serials' => "2510106871\n2510106881",
            ]],
            paymentMethod: 'Cash',
            actor: $this->actor,
            statutory: ['place_of_supply_state' => 'Delhi'],
        );

        $this->assertSame(1, InventorySaleLine::query()->where('sale_id', $sale->id)->count());
        $line = $sale->lines->first();
        $this->assertSame(2, $line->qty);
        $this->assertSame(
            ['2510106871', '2510106881'],
            $sale->serials->pluck('serial.serial_number')->sort()->values()->all()
        );
        $this->assertSame(58997.64, (float) $line->line_total);
    }

    public function test_duplicate_serial_is_rejected(): void
    {
        $product = $this->serializedProduct('RBMBAS50L1-DUP', 'Duplicate serial product');
        $this->stock->stockInSerialized($product, $this->branch, ['2510999001'], $this->actor);

        $this->expectException(ValidationException::class);

        $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Walk-in', 'phone' => '9000002002'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 2,
                'serials' => "2510999001\n2510999001",
            ]],
            paymentMethod: 'Cash',
            actor: $this->actor,
        );
    }

    public function test_qty_must_match_serial_count(): void
    {
        $product = $this->serializedProduct('RBMBAS50L1-MISMATCH', 'Mismatch serial product');
        $this->stock->stockInSerialized($product, $this->branch, ['2510999002', '2510999003'], $this->actor);

        $this->expectException(ValidationException::class);

        $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Walk-in', 'phone' => '9000002003'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => "2510999002\n2510999003",
            ]],
            paymentMethod: 'Cash',
            actor: $this->actor,
        );
    }

    private function serializedProduct(string $sku, string $name): InventoryProduct
    {
        return InventoryProduct::query()->create([
            'sku' => $sku,
            'name' => $name,
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 24999,
            'is_serialized' => true,
            'is_active' => true,
        ]);
    }
}
