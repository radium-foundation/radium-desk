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

    public function test_marc11_qty_two_at_catalog_price_is_tax_inclusive_8257_64(): void
    {
        $product = $this->serializedProduct(
            'RBMARC11L1',
            'Mantra MARC11-L1 Slick Capacitive Fingerprint Scanner',
            3499,
        );
        $this->stock->stockInSerialized($product, $this->branch, ['2503104063', '2503104086'], $this->actor);

        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Walk-in', 'phone' => '9000002391'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 2,
                'unit_price' => 3499,
                'serials' => "2503104063\n2503104086",
            ]],
            paymentMethod: 'Cash',
            actor: $this->actor,
            statutory: ['place_of_supply_state' => 'Delhi'],
        );

        $line = $sale->lines->first();
        $this->assertSame(1, $sale->lines->count());
        $this->assertSame(2, (int) $line->qty);
        $this->assertSame(3499.0, (float) $line->unit_price);
        $this->assertSame(0.0, (float) $line->discount);
        $this->assertSame(6998.0, (float) $sale->subtotal);
        $this->assertSame(1259.64, (float) $sale->tax);
        $this->assertSame(8257.64, (float) $line->line_total);
        $this->assertSame(8257.64, (float) $sale->total);
        $this->assertSame(2, $sale->serials->count());
    }

    public function test_overridden_unit_price_2300_qty_two_does_not_keep_catalog_line_total(): void
    {
        $product = $this->serializedProduct(
            'RBMARC11L1-OVR',
            'Mantra MARC11 override',
            3499,
        );
        $this->stock->stockInSerialized($product, $this->branch, ['2503999001', '2503999002'], $this->actor);

        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Walk-in', 'phone' => '9000002392'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 2,
                'unit_price' => 2300,
                'serials' => "2503999001\n2503999002",
            ]],
            paymentMethod: 'Cash',
            actor: $this->actor,
            statutory: ['place_of_supply_state' => 'Delhi'],
        );

        $line = $sale->lines->first();
        $this->assertSame(2300.0, (float) $line->unit_price);
        $this->assertSame(4600.0, (float) $sale->subtotal);
        $this->assertSame(828.0, (float) $sale->tax);
        $this->assertSame(5428.0, (float) $line->line_total);
        $this->assertSame(5428.0, (float) $sale->total);
        $this->assertNotEquals(8257.64, (float) $line->line_total);
    }

    public function test_single_serial_uses_one_unit_price_not_qty_squared(): void
    {
        $product = $this->serializedProduct('RBMARC11L1-ONE', 'Mantra MARC11 one', 3499);
        $this->stock->stockInSerialized($product, $this->branch, ['2503999111'], $this->actor);

        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Walk-in', 'phone' => '9000002393'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'unit_price' => 3499,
                'serials' => '2503999111',
            ]],
            paymentMethod: 'Cash',
            actor: $this->actor,
            statutory: ['place_of_supply_state' => 'Delhi'],
        );

        $line = $sale->lines->first();
        $this->assertSame(1, (int) $line->qty);
        $this->assertSame(3499.0, (float) $sale->subtotal);
        $this->assertSame(629.82, (float) $sale->tax);
        $this->assertSame(4128.82, (float) $line->line_total);
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

    private function serializedProduct(string $sku, string $name, float $unitPrice = 24999): InventoryProduct
    {
        return InventoryProduct::query()->create([
            'sku' => $sku,
            'name' => $name,
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => $unitPrice,
            'is_serialized' => true,
            'is_active' => true,
        ]);
    }
}
