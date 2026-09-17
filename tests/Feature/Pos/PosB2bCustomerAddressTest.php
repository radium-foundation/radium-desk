<?php

namespace Tests\Feature\Pos;

use App\Enums\InventorySaleStatus;
use App\Enums\InventorySerialStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\InventorySerial;
use App\Models\InventoryUserBranch;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DisablesRequestForgeryProtection;
use Tests\TestCase;

class PosB2bCustomerAddressTest extends TestCase
{
    use DisablesRequestForgeryProtection;
    use RefreshDatabase;

    private User $seller;

    private InventoryBranch $branch;

    private InventoryProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        $this->branch = InventoryBranch::query()->create([
            'code' => 'B2B',
            'name' => 'B2B Counter',
            'is_active' => true,
        ]);
        InventoryUserBranch::query()->create([
            'user_id' => $this->seller->id,
            'branch_id' => $this->branch->id,
        ]);
        $this->product = InventoryProduct::query()->create([
            'sku' => 'OTG-B2B',
            'name' => 'OTG B2B',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInQuantity($this->product, $this->branch, 5, $this->seller);

        $this->disableRequestForgeryProtection();
        $this->configureLocationSellerIdentity();
        config([
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.post_finance_journals' => false,
        ]);
    }

    public function test_existing_b2b_customer_with_complete_sale_snapshot_passes_validation(): void
    {
        $customer = InventoryCustomer::query()->create([
            'name' => 'Repeat B2B Buyer',
            'phone' => '9155777195',
            'email' => 'buyer@example.test',
            'gstin' => '10BMKPV2848E1Z1',
        ]);

        InventorySale::query()->create([
            'sale_no' => 'POS-000501',
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'buyer_gstin' => '10BMKPV2848E1Z1',
            'billing_address' => 'Kadamkuan, Patna',
            'billing_address_structured' => [
                'line1' => 'Kadamkuan, Patna',
                'city' => 'Patna',
                'state' => 'Bihar',
                'pincode' => '800003',
            ],
            'place_of_supply_state' => 'Bihar',
            'status' => InventorySaleStatus::Completed,
            'subtotal' => 100,
            'discount' => 0,
            'tax' => 18,
            'total' => 118,
            'payment_method' => 'Cash',
            'completed_at' => now(),
        ]);

        $this->actingAs($this->seller)
            ->getJson(route('pos.customers.show', $customer))
            ->assertOk()
            ->assertJsonPath('billing_city', 'Patna')
            ->assertJsonPath('billing_state', 'Bihar')
            ->assertJsonPath('billing_pincode', '800003');

        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), $this->payload([
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone,
                'customer_email' => $customer->email,
                'buyer_gstin' => '10BMKPV2848E1Z1',
                'billing_address' => 'Kadamkuan, Patna',
                'billing_city' => 'Patna',
                'billing_state' => 'Bihar',
                'billing_pincode' => '800003',
                'place_of_supply_state' => 'Bihar',
                'idempotency_key' => 'b2b-complete-address',
            ]))
            ->assertRedirect();

        $sale = InventorySale::query()->where('idempotency_key', 'b2b-complete-address')->firstOrFail();
        $this->assertSame('Patna', $sale->billing_address_structured['city'] ?? null);
        $this->assertSame('Bihar', $sale->billing_address_structured['state'] ?? null);
        $this->assertSame('800003', $sale->billing_address_structured['pincode'] ?? null);
    }

    public function test_b2b_sale_rejects_missing_city(): void
    {
        $this->actingAs($this->seller)
            ->from(route('pos.counter.create', ['branch_id' => $this->branch->id]))
            ->post(route('pos.counter.store'), $this->payload([
                'buyer_gstin' => '10BMKPV2848E1Z1',
                'billing_state' => 'Bihar',
                'billing_pincode' => '800003',
                'place_of_supply_state' => 'Bihar',
            ]))
            ->assertSessionHasErrors('billing_city');

        $this->assertSame(0, InventorySale::query()->count());
    }

    public function test_b2b_sale_rejects_missing_state(): void
    {
        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), $this->payload([
                'buyer_gstin' => '10BMKPV2848E1Z1',
                'billing_city' => 'Patna',
                'billing_pincode' => '800003',
            ]))
            ->assertSessionHasErrors('billing_state');

        $this->assertSame(0, InventorySale::query()->count());
    }

    public function test_b2b_sale_rejects_missing_pin(): void
    {
        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), $this->payload([
                'buyer_gstin' => '10BMKPV2848E1Z1',
                'billing_city' => 'Patna',
                'billing_state' => 'Bihar',
                'place_of_supply_state' => 'Bihar',
            ]))
            ->assertSessionHasErrors('billing_pincode');

        $this->assertSame(0, InventorySale::query()->count());
    }

    public function test_b2c_sale_does_not_require_structured_billing_fields(): void
    {
        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), $this->payload([
                'idempotency_key' => 'b2c-no-address-fields',
            ]))
            ->assertRedirect();

        $sale = InventorySale::query()->where('idempotency_key', 'b2c-no-address-fields')->firstOrFail();
        $this->assertNull($sale->buyer_gstin);
        $this->assertNull($sale->billing_address_structured);
    }

    public function test_place_of_supply_fills_billing_state_when_gstin_present(): void
    {
        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), $this->payload([
                'buyer_gstin' => '10BMKPV2848E1Z1',
                'billing_city' => 'Patna',
                'billing_pincode' => '800003',
                'place_of_supply_state' => 'Bihar',
                'idempotency_key' => 'b2b-state-from-pos',
            ]))
            ->assertRedirect();

        $sale = InventorySale::query()->where('idempotency_key', 'b2b-state-from-pos')->firstOrFail();
        $this->assertSame('Bihar', $sale->billing_address_structured['state'] ?? null);
    }

    public function test_serialized_b2b_sale_merges_serials_and_preserves_address(): void
    {
        $serialized = InventoryProduct::query()->create([
            'sku' => 'RBMFS110L1',
            'name' => 'Mantra MFS 110 L1',
            'gst_percentage' => 18,
            'unit_price' => 2500,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized(
            $serialized,
            $this->branch,
            ['SER-B2B-1', 'SER-B2B-2'],
            $this->seller,
        );

        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), $this->payload([
                'product_id' => $serialized->id,
                'lines' => [
                    [
                        'product_id' => $serialized->id,
                        'qty' => 1,
                        'serials' => "SER-B2B-1\n",
                    ],
                    [
                        'product_id' => $serialized->id,
                        'qty' => 1,
                        'serials' => "SER-B2B-2\n",
                    ],
                ],
                'buyer_gstin' => '10BMKPV2848E1Z1',
                'billing_address' => 'Kadamkuan, Patna',
                'billing_city' => 'Patna',
                'billing_state' => 'Bihar',
                'billing_pincode' => '800003',
                'place_of_supply_state' => 'Bihar',
                'idempotency_key' => 'b2b-serial-merge',
            ]))
            ->assertRedirect();

        $sale = InventorySale::query()->where('idempotency_key', 'b2b-serial-merge')->firstOrFail();
        $line = $sale->lines()->firstOrFail();

        $this->assertSame(1, $sale->lines()->count());
        $this->assertSame(2, $line->qty);
        $this->assertSame(2, $sale->serials()->count());
        $this->assertSame('Patna', $sale->billing_address_structured['city'] ?? null);
        $this->assertSame(
            2,
            InventorySerial::query()
                ->whereIn('serial_number', ['SER-B2B-1', 'SER-B2B-2'])
                ->where('status', InventorySerialStatus::Sold)
                ->count(),
        );
    }

    public function test_counter_page_exposes_b2b_billing_fields_and_customer_lookup_wiring(): void
    {
        $response = $this->actingAs($this->seller)
            ->get(route('pos.counter.create', ['branch_id' => $this->branch->id]));

        $response->assertOk();
        $response->assertSee('id="billing_city"', false);
        $response->assertSee('id="billing_state"', false);
        $response->assertSee('id="billing_pincode"', false);
        $response->assertSee('billing_city', false);
        $response->assertSee('consolidateSerializedCart', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $productId = $overrides['product_id'] ?? $this->product->id;
        unset($overrides['product_id']);

        return array_merge([
            'branch_id' => $this->branch->id,
            'customer_name' => 'Walk-in',
            'customer_phone' => '9000000091',
            'payment_method' => 'Cash',
            'discount' => 0,
            'lines' => [[
                'product_id' => $productId,
                'qty' => 1,
                'unit_price' => $this->product->unit_price,
                'discount' => 0,
            ]],
        ], $overrides);
    }
}
