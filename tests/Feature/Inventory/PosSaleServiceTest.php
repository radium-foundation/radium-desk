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
use App\Services\Finance\PosSaleJournalService;
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
        $this->assertMatchesRegularExpression('/^INV-HQ-\d{4}-\d{5}$/', (string) $sale->invoice_number);
        if ($sale->finance_handoff_status === InventoryFinanceHandoffStatus::Posted) {
            $this->assertNotNull($sale->finance_journal_id);
        }
    }

    public function test_idempotent_complete_returns_the_same_sale_without_reselling(): void
    {
        $product = $this->serializedProduct('MFS110-IDEM', 'Mantra MFS110 Idem');
        $this->stock->stockInSerialized($product, $this->branch, ['POS-IDEM-1'], $this->actor);

        $payload = [
            'branch' => $this->branch,
            'customer' => ['name' => 'Anita', 'phone' => '9999911111'],
            'lines' => [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['POS-IDEM-1'],
            ]],
            'paymentMethod' => 'Cash',
            'actor' => $this->actor,
            'idempotencyKey' => 'counter-retry-1',
        ];

        $first = $this->sales->completeSale(...$payload);
        $second = $this->sales->completeSale(...$payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, InventorySale::query()->count());
        $this->assertSame(InventorySerialStatus::Sold, InventorySerial::query()->where('serial_number', 'POS-IDEM-1')->value('status'));
    }

    public function test_finance_failure_rolls_back_inventory(): void
    {
        $product = $this->serializedProduct('MFS110-FIN', 'Mantra finance fail');
        $this->stock->stockInSerialized($product, $this->branch, ['POS-FIN-1'], $this->actor);

        $this->mock(PosSaleJournalService::class, function ($mock) {
            $mock->shouldReceive('postForSale')->andThrow(ValidationException::withMessages([
                'finance' => 'Finance accounts are not configured. The sale was not completed and stock was not taken.',
            ]));
        });
        $sales = $this->app->make(PosSaleService::class);

        try {
            $sales->completeSale(
                branch: $this->branch,
                customer: ['name' => 'Fail', 'phone' => '9999922222'],
                lines: [[
                    'product_id' => $product->id,
                    'qty' => 1,
                    'serials' => ['POS-FIN-1'],
                ]],
                paymentMethod: 'Cash',
                actor: $this->actor,
            );
            $this->fail('Expected finance failure to abort the sale.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('finance', $exception->errors());
        }

        $this->assertSame(0, InventorySale::query()->count());
        $this->assertSame(InventorySerialStatus::Available, InventorySerial::query()->where('serial_number', 'POS-FIN-1')->value('status'));
        $this->assertSame(1, (int) $product->balances()->where('branch_id', $this->branch->id)->value('available_qty'));
    }

    public function test_reserved_serial_cannot_be_sold_by_another_sale(): void
    {
        $product = $this->serializedProduct('MFS110-RSV', 'Mantra reserved');
        $this->stock->stockInSerialized($product, $this->branch, ['POS-RSV-1'], $this->actor);
        $this->stock->reserveSerials($this->branch, ['POS-RSV-1'], $this->actor);

        $this->expectException(ValidationException::class);
        $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Other counter', 'phone' => '9999933333'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['POS-RSV-1'],
            ]],
            paymentMethod: 'Cash',
            actor: $this->actor,
        );
    }

    public function test_sale_can_consume_matching_reservation(): void
    {
        $product = $this->serializedProduct('MFS110-USE', 'Mantra reserved use');
        $this->stock->stockInSerialized($product, $this->branch, ['POS-USE-1'], $this->actor);
        $reservation = $this->stock->reserveSerials($this->branch, ['POS-USE-1'], $this->actor);

        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Held', 'phone' => '9999944444'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['POS-USE-1'],
            ]],
            paymentMethod: 'UPI',
            actor: $this->actor,
            reservation: $reservation,
        );

        $this->assertSame(InventorySaleStatus::Completed, $sale->status);
        $this->assertSame(InventorySerialStatus::Sold, InventorySerial::query()->where('serial_number', 'POS-USE-1')->value('status'));
        $this->assertSame($sale->id, $reservation->fresh()->sale_id);
    }

    public function test_multi_item_sale_deducts_each_line(): void
    {
        $serialized = $this->serializedProduct('MFS110-MULTI', 'Mantra multi');
        $qtyProduct = InventoryProduct::query()->create([
            'sku' => 'OTG-MULTI',
            'name' => 'OTG multi',
            'gst_percentage' => 18,
            'unit_price' => 50,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        $this->stock->stockInSerialized($serialized, $this->branch, ['POS-MULTI-1'], $this->actor);
        $this->stock->stockInQuantity($qtyProduct, $this->branch, 4, $this->actor);

        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Bundle', 'phone' => '9999955555'],
            lines: [
                [
                    'product_id' => $serialized->id,
                    'qty' => 1,
                    'serials' => ['POS-MULTI-1'],
                ],
                [
                    'product_id' => $qtyProduct->id,
                    'qty' => 2,
                ],
            ],
            paymentMethod: 'Card',
            actor: $this->actor,
        );

        $this->assertSame(2, $sale->lines()->count());
        $this->assertSame(0, (int) $serialized->balances()->where('branch_id', $this->branch->id)->value('available_qty'));
        $this->assertSame(2, (int) $qtyProduct->balances()->where('branch_id', $this->branch->id)->value('available_qty'));
    }

    public function test_variant_quantity_is_tracked_separately_and_sold(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'CABLE-PARENT',
            'name' => 'USB Cable',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        $variant = $product->variants()->create([
            'sku' => 'CABLE-1M',
            'name' => '1 metre',
            'unit_price' => 90,
            'is_active' => true,
        ]);
        $this->stock->stockInQuantity($product, $this->branch, 5, $this->actor, $variant);

        $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Variant buyer', 'phone' => '9999966666'],
            lines: [[
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'qty' => 2,
            ]],
            paymentMethod: 'Cash',
            actor: $this->actor,
        );

        $this->assertSame(3, (int) $product->balances()->where('branch_id', $this->branch->id)->where('variant_id', $variant->id)->value('available_qty'));
    }

    public function test_concurrent_serialized_sale_protection_second_complete_fails(): void
    {
        $product = $this->serializedProduct('MFS110-CON', 'Mantra concurrent');
        $this->stock->stockInSerialized($product, $this->branch, ['POS-CON-1'], $this->actor);

        $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'First counter', 'phone' => '9999977777'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['POS-CON-1'],
            ]],
            paymentMethod: 'Cash',
            actor: $this->actor,
        );

        $this->expectException(ValidationException::class);
        $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Second counter', 'phone' => '9999988888'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['POS-CON-1'],
            ]],
            paymentMethod: 'Cash',
            actor: $this->actor,
        );
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

    private function serializedProduct(string $sku = 'MFS110-POS', string $name = 'Mantra MFS110'): InventoryProduct
    {
        return InventoryProduct::query()->create([
            'sku' => $sku,
            'name' => $name,
            'gst_percentage' => 18,
            'unit_price' => 2500,
            'is_serialized' => true,
            'is_active' => true,
        ]);
    }
}
