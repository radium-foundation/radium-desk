<?php

namespace Tests\Feature\Pos;

use App\Enums\InventorySaleStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\InventorySale;
use App\Models\InventoryUserBranch;
use App\Models\User;
use App\Services\Pos\PosCustomerLookupService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosCustomerLookupTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private InventoryBranch $branch;

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
    }

    public function test_search_finds_customer_by_partial_phone_and_name(): void
    {
        InventoryCustomer::query()->create([
            'name' => 'Robert Buyer',
            'phone' => '9898989898',
            'email' => 'robert@example.test',
            'gstin' => '07AAAAA0000A1Z5',
        ]);

        $this->actingAs($this->seller)
            ->getJson(route('pos.customers.search', ['q' => '98']))
            ->assertOk()
            ->assertJsonPath('customers.0.phone', '9898989898');

        $this->actingAs($this->seller)
            ->getJson(route('pos.customers.search', ['q' => 'ob']))
            ->assertOk()
            ->assertJsonPath('customers.0.name', 'Robert Buyer');
    }

    public function test_show_customer_populates_latest_sale_billing_snapshot(): void
    {
        $customer = InventoryCustomer::query()->create([
            'name' => 'Repeat Buyer',
            'phone' => '9000001234',
            'email' => 'repeat@example.test',
            'gstin' => '07AAAAA0000A1Z5',
        ]);

        InventorySale::query()->create([
            'sale_no' => 'POS-000099',
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'buyer_gstin' => '07AAAAA0000A1Z5',
            'billing_address' => '12 Connaught Place',
            'billing_address_structured' => [
                'line1' => '12 Connaught Place',
                'city' => 'New Delhi',
                'state' => 'Delhi',
                'pincode' => '110001',
            ],
            'place_of_supply_state' => 'Delhi',
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
            ->assertJsonPath('found', true)
            ->assertJsonPath('billing_address', '12 Connaught Place')
            ->assertJsonPath('billing_city', 'New Delhi')
            ->assertJsonPath('billing_state', 'Delhi')
            ->assertJsonPath('billing_pincode', '110001')
            ->assertJsonPath('place_of_supply_state', 'Delhi');
    }

    public function test_exact_phone_lookup_returns_full_payload(): void
    {
        $customer = InventoryCustomer::query()->create([
            'name' => 'Exact Phone',
            'phone' => '9111111111',
        ]);

        $payload = app(PosCustomerLookupService::class)->resolveByPhone('9111111111');

        $this->assertTrue($payload['found']);
        $this->assertSame($customer->id, $payload['id']);
        $this->assertSame('Exact Phone', $payload['name']);
    }
}
