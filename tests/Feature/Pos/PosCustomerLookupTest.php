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

    private User $serviceSeller;

    private InventoryBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        $this->serviceSeller = User::factory()->create(['is_active' => true]);
        $this->serviceSeller->givePermissionTo(RolePermissionSeeder::PERMISSION_SERVICE_POS_SELL);

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
            'name' => 'Ramsonit Retail',
            'phone' => '9898989898',
            'email' => 'ramsonit@example.test',
            'gstin' => '07AAAAA0000A1Z5',
        ]);

        $this->actingAs($this->seller)
            ->getJson(route('pos.customers.search', ['q' => '98']))
            ->assertOk()
            ->assertJsonPath('customers.0.phone', '9898989898');

        $this->actingAs($this->seller)
            ->getJson(route('pos.customers.search', ['q' => 'Ramson']))
            ->assertOk()
            ->assertJsonPath('customers.0.name', 'Ramsonit Retail');
    }

    public function test_search_finds_customer_by_email(): void
    {
        InventoryCustomer::query()->create([
            'name' => 'Email Buyer',
            'phone' => '9000004321',
            'email' => 'buyer@example.test',
        ]);

        $this->actingAs($this->seller)
            ->getJson(route('pos.customers.search', ['q' => 'buyer@example']))
            ->assertOk()
            ->assertJsonPath('customers.0.email', 'buyer@example.test');
    }

    public function test_service_pos_seller_can_search_customers_without_pos_sell_permission(): void
    {
        InventoryCustomer::query()->create([
            'name' => 'Service POS Buyer',
            'phone' => '9111222333',
        ]);

        $this->actingAs($this->serviceSeller)
            ->getJson(route('pos.customers.search', ['q' => 'Service POS']))
            ->assertOk()
            ->assertJsonPath('customers.0.name', 'Service POS Buyer');
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
            ->assertJsonPath('id', $customer->id)
            ->assertJsonPath('billing_address', '12 Connaught Place')
            ->assertJsonPath('billing_state', 'Delhi')
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

    public function test_short_queries_return_empty_results(): void
    {
        InventoryCustomer::query()->create([
            'name' => 'Short Query',
            'phone' => '9000000001',
        ]);

        $this->actingAs($this->seller)
            ->getJson(route('pos.customers.search', ['q' => 'S']))
            ->assertOk()
            ->assertJsonPath('customers', []);
    }
}
