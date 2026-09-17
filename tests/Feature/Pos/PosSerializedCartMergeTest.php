<?php

namespace Tests\Feature\Pos;

use App\Enums\InventorySerialStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\InventorySerial;
use App\Models\InventoryUserBranch;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosSerializedCartMergeTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private InventoryBranch $branch;

    private InventoryProduct $serializedProduct;

    private InventoryProduct $quantityProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        $this->branch = InventoryBranch::query()->create([
            'code' => 'MERGE',
            'name' => 'Merge Counter',
            'is_active' => true,
        ]);
        InventoryUserBranch::query()->create([
            'user_id' => $this->seller->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->serializedProduct = InventoryProduct::query()->create([
            'sku' => 'RBMIS100IR',
            'name' => 'Radium biometric scanner',
            'gst_percentage' => 18,
            'unit_price' => 2500,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $this->quantityProduct = InventoryProduct::query()->create([
            'sku' => 'OTG-MERGE',
            'name' => 'OTG Cable',
            'gst_percentage' => 18,
            'unit_price' => 50,
            'is_serialized' => false,
            'is_active' => true,
        ]);

        $stock = app(InventoryStockService::class);
        $stock->stockInSerialized(
            $this->serializedProduct,
            $this->branch,
            ['11012587', '11009853', '11007771'],
            $this->seller,
        );
        $stock->stockInQuantity($this->quantityProduct, $this->branch, 5, $this->seller);

        config(['statutory_invoices.auto_issue_on_pos_complete' => false]);
    }

    public function test_multiple_unique_serials_for_same_product_produce_one_cart_line_with_qty_equal_to_serial_count(): void
    {
        $sale = app(PosSaleService::class)->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Serial merge buyer', 'phone' => '9999900101'],
            lines: [
                [
                    'product_id' => $this->serializedProduct->id,
                    'qty' => 1,
                    'serials' => ['11012587'],
                ],
                [
                    'product_id' => $this->serializedProduct->id,
                    'qty' => 1,
                    'serials' => ['11009853'],
                ],
                [
                    'product_id' => $this->serializedProduct->id,
                    'qty' => 1,
                    'serials' => ['11007771'],
                ],
            ],
            paymentMethod: 'Cash',
            actor: $this->seller,
            idempotencyKey: 'serial-merge-sale-1',
        );

        $line = $sale->lines()->with('serials.serial')->firstOrFail();
        $serialNumbers = $line->serials->map(fn ($assignment) => $assignment->serial?->serial_number)->sort()->values()->all();

        $this->assertSame(1, $sale->lines()->count());
        $this->assertSame(3, $line->qty);
        $this->assertSame(['11007771', '11009853', '11012587'], $serialNumbers);
        $this->assertSame(3, $sale->serials()->count());
        $this->assertSame(
            3,
            InventorySerial::query()
                ->whereIn('serial_number', ['11012587', '11009853', '11007771'])
                ->where('status', InventorySerialStatus::Sold)
                ->count(),
        );
    }

    public function test_duplicate_serial_submission_does_not_double_sell_inventory(): void
    {
        $sale = app(PosSaleService::class)->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Duplicate serial buyer', 'phone' => '9999900102'],
            lines: [
                [
                    'product_id' => $this->serializedProduct->id,
                    'qty' => 1,
                    'serials' => ['11012587'],
                ],
                [
                    'product_id' => $this->serializedProduct->id,
                    'qty' => 1,
                    'serials' => ['11012587'],
                ],
            ],
            paymentMethod: 'Cash',
            actor: $this->seller,
            idempotencyKey: 'serial-merge-dup-1',
        );

        $line = $sale->lines()->firstOrFail();

        $this->assertSame(1, $sale->lines()->count());
        $this->assertSame(1, $line->qty);
        $this->assertSame(1, $sale->serials()->count());
    }

    public function test_different_products_remain_separate_sale_lines(): void
    {
        $otherSerialized = InventoryProduct::query()->create([
            'sku' => 'RBMIS200IR',
            'name' => 'Other scanner',
            'gst_percentage' => 18,
            'unit_price' => 3000,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized(
            $otherSerialized,
            $this->branch,
            ['22001111'],
            $this->seller,
        );

        $sale = app(PosSaleService::class)->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Two product buyer', 'phone' => '9999900103'],
            lines: [
                [
                    'product_id' => $this->serializedProduct->id,
                    'qty' => 1,
                    'serials' => ['11012587'],
                ],
                [
                    'product_id' => $otherSerialized->id,
                    'qty' => 1,
                    'serials' => ['22001111'],
                ],
            ],
            paymentMethod: 'Cash',
            actor: $this->seller,
            idempotencyKey: 'serial-merge-two-products',
        );

        $this->assertSame(2, $sale->lines()->count());
        $this->assertSame(2, $sale->serials()->count());
    }

    public function test_non_serialized_counter_submission_remains_unchanged(): void
    {
        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), [
                'branch_id' => $this->branch->id,
                'customer_name' => 'Quantity buyer',
                'customer_phone' => '9999900104',
                'payment_method' => 'Cash',
                'idempotency_key' => 'qty-merge-unchanged',
                'lines' => [
                    [
                        'product_id' => $this->quantityProduct->id,
                        'qty' => 2,
                    ],
                ],
            ])
            ->assertRedirect();

        $sale = InventorySale::query()->where('idempotency_key', 'qty-merge-unchanged')->firstOrFail();
        $line = $sale->lines()->firstOrFail();

        $this->assertSame(1, $sale->lines()->count());
        $this->assertSame(2, $line->qty);
        $this->assertSame(0, $sale->serials()->count());
    }

    public function test_counter_store_merges_duplicate_serialized_lines_before_sale_completion(): void
    {
        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), [
                'branch_id' => $this->branch->id,
                'customer_name' => 'Counter merge buyer',
                'customer_phone' => '9999900105',
                'payment_method' => 'Cash',
                'idempotency_key' => 'counter-serial-merge',
                'lines' => [
                    [
                        'product_id' => $this->serializedProduct->id,
                        'qty' => 1,
                        'serials' => "11012587\n",
                    ],
                    [
                        'product_id' => $this->serializedProduct->id,
                        'qty' => 1,
                        'serials' => "11009853\n",
                    ],
                ],
            ])
            ->assertRedirect();

        $sale = InventorySale::query()->where('idempotency_key', 'counter-serial-merge')->firstOrFail();
        $line = $sale->lines()->with('serials.serial')->firstOrFail();
        $serialNumbers = $line->serials->map(fn ($assignment) => $assignment->serial?->serial_number)->sort()->values()->all();

        $this->assertSame(1, $sale->lines()->count());
        $this->assertSame(2, $line->qty);
        $this->assertSame(['11009853', '11012587'], $serialNumbers);
    }

    public function test_counter_page_exposes_serial_merge_cart_logic(): void
    {
        $response = $this->actingAs($this->seller)
            ->get(route('pos.counter.create', ['branch_id' => $this->branch->id]));

        $response->assertOk();
        $response->assertSee('consolidateSerializedCart', false);
        $response->assertSee('item.is_serialized', false);
        $response->assertSee('existing.serials.push(serialNumber)', false);
    }
}
