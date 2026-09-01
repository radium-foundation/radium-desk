<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventoryFinanceHandoffStatus;
use App\Enums\InventorySaleStatus;
use App\Enums\InventorySerialStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\InventorySerial;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PosSaleServiceTest extends TestCase
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

    public function test_serialized_sale_assigns_serial_deducts_stock_and_hands_off_to_finance(): void
    {
        $product = $this->serializedProduct();
        $this->stock->stockInSerialized($product, $this->branch, ['POS-1001'], $this->actor);

        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Anita', 'phone' => '9999900001'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['POS-1001'],
            ]],
            paymentMethod: 'Cash',
            actor: $this->actor,
        );

        $serial = InventorySerial::query()->where('serial_number', 'POS-1001')->firstOrFail();
        $this->assertSame(InventorySaleStatus::Completed, $sale->status);
        $this->assertNotNull($sale->invoice_number);
        $this->assertSame(InventorySerialStatus::Sold, $serial->status);
        $this->assertSame(0, (int) $product->balances()->where('branch_id', $this->branch->id)->value('available_qty'));
        $this->assertSame(1, $sale->serials()->count());
        $this->assertContains($sale->finance_handoff_status, [
            InventoryFinanceHandoffStatus::Posted,
            InventoryFinanceHandoffStatus::Skipped,
        ]);
        $this->assertTrue((float) $sale->total > 0);
    }

    public function test_serial_cannot_be_sold_twice(): void
    {
        $product = $this->serializedProduct();
        $this->stock->stockInSerialized($product, $this->branch, ['POS-2002'], $this->actor);

        $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Ravi', 'phone' => '9999900002'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['POS-2002'],
            ]],
            paymentMethod: 'UPI',
            actor: $this->actor,
        );

        $this->expectException(ValidationException::class);
        $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Other', 'phone' => '9999900003'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['POS-2002'],
            ]],
            paymentMethod: 'UPI',
            actor: $this->actor,
        );
    }

    public function test_quantity_sale_deducts_available_stock(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'OTG-01',
            'name' => 'OTG Cable',
            'gst_percentage' => 18,
            'unit_price' => 50,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        $this->stock->stockInQuantity($product, $this->branch, 5, $this->actor);

        $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Shop', 'phone' => '9999900004'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 2,
            ]],
            paymentMethod: 'Cash',
            actor: $this->actor,
            headerDiscount: 10,
        );

        $this->assertSame(3, (int) $product->balances()->where('branch_id', $this->branch->id)->value('available_qty'));
    }

    public function test_cancel_restores_serial_to_selling_branch(): void
    {
        $product = $this->serializedProduct();
        $this->stock->stockInSerialized($product, $this->branch, ['POS-3003'], $this->actor);
        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Return Customer', 'phone' => '9999900005'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['POS-3003'],
            ]],
            paymentMethod: 'Cash',
            actor: $this->actor,
        );

        $cancelled = $this->sales->cancelSale($sale, $this->actor, 'Customer changed mind');
        $serial = InventorySerial::query()->where('serial_number', 'POS-3003')->firstOrFail();

        $this->assertSame(InventorySaleStatus::Cancelled, $cancelled->status);
        $this->assertSame(InventorySerialStatus::Available, $serial->status);
        $this->assertSame($this->branch->id, $serial->branch_id);
        $this->assertSame(1, (int) $product->balances()->where('branch_id', $this->branch->id)->value('available_qty'));
    }

    public function test_return_restores_quantity_stock(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'OTG-02',
            'name' => 'OTG Cable 2',
            'gst_percentage' => 18,
            'unit_price' => 80,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        $this->stock->stockInQuantity($product, $this->branch, 3, $this->actor);
        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Qty Customer', 'phone' => '9999900006'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 2,
            ]],
            paymentMethod: 'Card',
            actor: $this->actor,
        );

        $this->sales->returnSale($sale, $this->actor, 'Defective');

        $this->assertSame(InventorySaleStatus::Returned, $sale->fresh()->status);
        $this->assertSame(3, (int) $product->balances()->where('branch_id', $this->branch->id)->value('available_qty'));
        $this->assertSame(1, InventorySale::query()->count());
    }

    public function test_serialized_line_requires_matching_serial_count(): void
    {
        $product = $this->serializedProduct();
        $this->stock->stockInSerialized($product, $this->branch, ['POS-4004'], $this->actor);

        $this->expectException(ValidationException::class);
        $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Missing Serial', 'phone' => '9999900007'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => [],
            ]],
            paymentMethod: 'Cash',
            actor: $this->actor,
        );
    }

    private function serializedProduct(): InventoryProduct
    {
        return InventoryProduct::query()->create([
            'sku' => 'MFS110-POS',
            'name' => 'Mantra MFS110',
            'gst_percentage' => 18,
            'unit_price' => 2500,
            'is_serialized' => true,
            'is_active' => true,
        ]);
    }
}
