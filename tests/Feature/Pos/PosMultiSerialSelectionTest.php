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
use App\Support\Inventory\PosSaleLineNormalizer;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\DisablesRequestForgeryProtection;
use Tests\TestCase;

class PosMultiSerialSelectionTest extends TestCase
{
    use DisablesRequestForgeryProtection;
    use RefreshDatabase;

    private User $seller;

    private InventoryBranch $branch;

    private InventoryProduct $serializedProduct;

    private InventoryProduct $otherSerializedProduct;

    private InventoryProduct $quantityProduct;

    /** @var list<string> */
    private array $serials = [
        'H22057-MP826D817041603-09/26',
        'H22028-MP826D817043063-09/26',
        'H22031-MP826D817043071-09/26',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        $this->branch = InventoryBranch::query()->create([
            'code' => 'MULTI',
            'name' => 'Multi Serial Counter',
            'is_active' => true,
        ]);
        InventoryUserBranch::query()->create([
            'user_id' => $this->seller->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->serializedProduct = InventoryProduct::query()->create([
            'sku' => 'RBUGR89GPS',
            'name' => 'GPS device',
            'gst_percentage' => 18,
            'unit_price' => 4999,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $this->otherSerializedProduct = InventoryProduct::query()->create([
            'sku' => 'RBOTHERGPS',
            'name' => 'Other GPS device',
            'gst_percentage' => 18,
            'unit_price' => 5999,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $this->quantityProduct = InventoryProduct::query()->create([
            'sku' => 'OTG-MULTI',
            'name' => 'OTG Cable',
            'gst_percentage' => 18,
            'unit_price' => 50,
            'is_serialized' => false,
            'is_active' => true,
        ]);

        $stock = app(InventoryStockService::class);
        $stock->stockInSerialized($this->serializedProduct, $this->branch, $this->serials, $this->seller);
        $stock->stockInSerialized($this->otherSerializedProduct, $this->branch, ['OTHER-SN-001', 'OTHER-SN-002'], $this->seller);
        $stock->stockInQuantity($this->quantityProduct, $this->branch, 5, $this->seller);

        InventorySerial::query()->create([
            'product_id' => $this->serializedProduct->id,
            'serial_number' => 'H22099-MP826D817043099-09/26',
            'branch_id' => $this->branch->id,
            'status' => InventorySerialStatus::Sold,
        ]);
        InventorySerial::query()->create([
            'product_id' => $this->serializedProduct->id,
            'serial_number' => 'H22100-MP826D817043100-09/26',
            'branch_id' => $this->branch->id,
            'status' => InventorySerialStatus::Reserved,
        ]);

        $this->disableRequestForgeryProtection();
        config(['statutory_invoices.auto_issue_on_pos_complete' => false]);
    }

    public function test_match_endpoint_accepts_space_separated_serials(): void
    {
        $response = $this->actingAs($this->seller)->getJson(route('pos.serials.match', [
            'branch_id' => $this->branch->id,
            'product_id' => $this->serializedProduct->id,
            'serials' => implode(' ', $this->serials),
        ]));

        $response->assertOk();
        $response->assertJsonCount(3, 'results');
        $response->assertJsonPath('results.0.status', 'available');
        $response->assertJsonPath('results.1.status', 'available');
        $response->assertJsonPath('results.2.status', 'available');
    }

    public function test_match_endpoint_accepts_comma_separated_serials(): void
    {
        $response = $this->actingAs($this->seller)->getJson(route('pos.serials.match', [
            'branch_id' => $this->branch->id,
            'product_id' => $this->serializedProduct->id,
            'serials' => implode(',', $this->serials),
        ]));

        $response->assertOk();
        $response->assertJsonCount(3, 'results');
    }

    public function test_match_endpoint_accepts_newline_separated_serials(): void
    {
        $response = $this->actingAs($this->seller)->getJson(route('pos.serials.match', [
            'branch_id' => $this->branch->id,
            'product_id' => $this->serializedProduct->id,
            'serials' => implode("\n", $this->serials),
        ]));

        $response->assertOk();
        $response->assertJsonCount(3, 'results');
    }

    public function test_match_endpoint_reports_unavailable_and_not_found_serials(): void
    {
        $response = $this->actingAs($this->seller)->getJson(route('pos.serials.match', [
            'branch_id' => $this->branch->id,
            'product_id' => $this->serializedProduct->id,
            'serials' => implode(' ', [
                $this->serials[0],
                'H22099-MP826D817043099-09/26',
                'H22100-MP826D817043100-09/26',
                'UNKNOWN-999',
            ]),
        ]));

        $response->assertOk();
        $response->assertJsonPath('results.0.status', 'available');
        $response->assertJsonPath('results.1.status', 'unavailable');
        $response->assertJsonPath('results.2.status', 'unavailable');
        $response->assertJsonPath('results.3.status', 'not_found');
    }

    public function test_match_endpoint_reports_wrong_product_serial(): void
    {
        $response = $this->actingAs($this->seller)->getJson(route('pos.serials.match', [
            'branch_id' => $this->branch->id,
            'product_id' => $this->serializedProduct->id,
            'serials' => 'OTHER-SN-001',
        ]));

        $response->assertOk();
        $response->assertJsonPath('results.0.status', 'wrong_product');
    }

    public function test_match_endpoint_reports_wrong_branch_serial(): void
    {
        $otherBranch = InventoryBranch::query()->create([
            'code' => 'REMOTE',
            'name' => 'Remote branch',
            'is_active' => true,
        ]);
        InventorySerial::query()->create([
            'product_id' => $this->serializedProduct->id,
            'serial_number' => 'REMOTE-SN-001',
            'branch_id' => $otherBranch->id,
            'status' => InventorySerialStatus::Available,
        ]);

        $response = $this->actingAs($this->seller)->getJson(route('pos.serials.match', [
            'branch_id' => $this->branch->id,
            'product_id' => $this->serializedProduct->id,
            'serials' => 'REMOTE-SN-001',
        ]));

        $response->assertOk();
        $response->assertJsonPath('results.0.status', 'wrong_branch');
    }

    public function test_counter_store_accepts_multiple_serials_on_one_line(): void
    {
        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), [
                'branch_id' => $this->branch->id,
                'customer_name' => 'Multi serial buyer',
                'customer_phone' => '9999900201',
                'payment_method' => 'Cash',
                'idempotency_key' => 'multi-serial-sale',
                'lines' => [
                    [
                        'product_id' => $this->serializedProduct->id,
                        'qty' => 3,
                        'serials' => implode("\n", $this->serials),
                    ],
                ],
            ])
            ->assertRedirect();

        $sale = InventorySale::query()->where('idempotency_key', 'multi-serial-sale')->firstOrFail();
        $line = $sale->lines()->with('serials.serial')->firstOrFail();
        $serialNumbers = $line->serials->map(fn ($assignment) => $assignment->serial?->serial_number)->sort()->values()->all();

        $this->assertSame(1, $sale->lines()->count());
        $this->assertSame(3, $line->qty);
        $this->assertSame(collect($this->serials)->sort()->values()->all(), $serialNumbers);
    }

    public function test_normalizer_syncs_quantity_to_selected_serial_count(): void
    {
        $normalized = PosSaleLineNormalizer::normalize([
            [
                'product_id' => $this->serializedProduct->id,
                'qty' => 2,
                'serials' => implode("\n", $this->serials),
            ],
        ]);

        $this->assertCount(1, $normalized);
        $this->assertSame(3, $normalized[0]['qty']);
        $this->assertSame($this->serials, $normalized[0]['serials']);
    }

    public function test_server_prevents_duplicate_serialized_allocation(): void
    {
        app(PosSaleService::class)->completeSale(
            branch: $this->branch,
            customer: ['name' => 'First buyer', 'phone' => '9999900203'],
            lines: [
                [
                    'product_id' => $this->serializedProduct->id,
                    'qty' => 1,
                    'serials' => [$this->serials[0]],
                ],
            ],
            paymentMethod: 'Cash',
            actor: $this->seller,
            idempotencyKey: 'first-serial-sale',
        );

        $this->expectException(ValidationException::class);

        app(PosSaleService::class)->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Second buyer', 'phone' => '9999900204'],
            lines: [
                [
                    'product_id' => $this->serializedProduct->id,
                    'qty' => 1,
                    'serials' => [$this->serials[0]],
                ],
            ],
            paymentMethod: 'Cash',
            actor: $this->seller,
            idempotencyKey: 'duplicate-serial-sale',
        );
    }

    public function test_multiple_serialized_products_keep_assignments_isolated(): void
    {
        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), [
                'branch_id' => $this->branch->id,
                'customer_name' => 'Two product buyer',
                'customer_phone' => '9999900205',
                'payment_method' => 'Cash',
                'idempotency_key' => 'two-serial-products',
                'lines' => [
                    [
                        'product_id' => $this->serializedProduct->id,
                        'qty' => 3,
                        'serials' => implode("\n", $this->serials),
                    ],
                    [
                        'product_id' => $this->otherSerializedProduct->id,
                        'qty' => 2,
                        'serials' => "OTHER-SN-001\nOTHER-SN-002",
                    ],
                ],
            ])
            ->assertRedirect();

        $sale = InventorySale::query()->where('idempotency_key', 'two-serial-products')->firstOrFail();
        $lines = $sale->lines()->with('serials.serial')->get()->keyBy('product_id');

        $this->assertSame(2, $sale->lines()->count());
        $this->assertSame(3, $lines[$this->serializedProduct->id]->qty);
        $this->assertSame(2, $lines[$this->otherSerializedProduct->id]->qty);
        $this->assertSame(
            collect($this->serials)->sort()->values()->all(),
            $lines[$this->serializedProduct->id]->serials->map(fn ($assignment) => $assignment->serial?->serial_number)->sort()->values()->all(),
        );
        $this->assertSame(
            ['OTHER-SN-001', 'OTHER-SN-002'],
            $lines[$this->otherSerializedProduct->id]->serials->map(fn ($assignment) => $assignment->serial?->serial_number)->sort()->values()->all(),
        );
    }

    public function test_non_serialized_product_workflow_remains_unchanged(): void
    {
        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), [
                'branch_id' => $this->branch->id,
                'customer_name' => 'Quantity buyer',
                'customer_phone' => '9999900206',
                'payment_method' => 'Cash',
                'idempotency_key' => 'qty-unchanged',
                'lines' => [
                    [
                        'product_id' => $this->quantityProduct->id,
                        'qty' => 2,
                    ],
                ],
            ])
            ->assertRedirect();

        $sale = InventorySale::query()->where('idempotency_key', 'qty-unchanged')->firstOrFail();
        $line = $sale->lines()->firstOrFail();

        $this->assertSame(2, $line->qty);
        $this->assertSame(0, $sale->serials()->count());
    }

    public function test_counter_page_exposes_multi_serial_ui_and_match_endpoint(): void
    {
        $response = $this->actingAs($this->seller)
            ->get(route('pos.counter.create', ['branch_id' => $this->branch->id]));

        $response->assertOk();
        $response->assertSee('pos-serial-entry', false);
        $response->assertSee('pos-serial-selected', false);
        $response->assertSee('matchSerialsUrl', false);
        $response->assertSee('processSerialTokens', false);
        $response->assertSee('parseSerialList', false);
        $response->assertSee('Already selected:', false);
    }
}
