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

    public function test_b2c_customer_without_master_address_or_sale_returns_empty_billing(): void
    {
        $customer = InventoryCustomer::query()->create([
            'name' => 'Walk-in B2C',
            'phone' => '9000001001',
            'email' => null,
            'gstin' => null,
        ]);

        $this->actingAs($this->seller)
            ->getJson(route('pos.customers.show', $customer))
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('id', $customer->id)
            ->assertJsonPath('name', 'Walk-in B2C')
            ->assertJsonPath('phone', '9000001001')
            ->assertJsonPath('email', null)
            ->assertJsonPath('gstin', null)
            ->assertJsonPath('billing_address', null)
            ->assertJsonPath('billing_city', null)
            ->assertJsonPath('billing_state', null)
            ->assertJsonPath('billing_pincode', null)
            ->assertJsonPath('place_of_supply_state', null)
            ->assertJsonPath('billing_source', PosCustomerLookupService::BILLING_SOURCE_NONE)
            ->assertJsonPath('place_of_supply_source', PosCustomerLookupService::BILLING_SOURCE_NONE);
    }

    public function test_b2c_customer_with_historical_sale_snapshot_does_not_override_master_gstin(): void
    {
        $customer = InventoryCustomer::query()->create([
            'name' => 'Repeat B2C',
            'phone' => '9000001234',
            'email' => 'repeat@example.test',
            'gstin' => null,
        ]);

        $this->createCompletedSale($customer, [
            'buyer_gstin' => '07AAAAA0000A1Z5',
            'billing_address' => '12 Connaught Place',
            'billing_address_structured' => [
                'line1' => '12 Connaught Place',
                'city' => 'New Delhi',
                'state' => 'Delhi',
                'pincode' => '110001',
            ],
            'place_of_supply_state' => 'Delhi',
        ]);

        $this->actingAs($this->seller)
            ->getJson(route('pos.customers.show', $customer))
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('gstin', null)
            ->assertJsonPath('billing_address', '12 Connaught Place')
            ->assertJsonPath('billing_city', 'New Delhi')
            ->assertJsonPath('billing_state', 'Delhi')
            ->assertJsonPath('billing_pincode', '110001')
            ->assertJsonPath('place_of_supply_state', 'Delhi')
            ->assertJsonPath('billing_source', PosCustomerLookupService::BILLING_SOURCE_LAST_SALE)
            ->assertJsonPath('place_of_supply_source', PosCustomerLookupService::BILLING_SOURCE_LAST_SALE);
    }

    public function test_b2c_completed_sale_without_address_does_not_fabricate_one(): void
    {
        $customer = InventoryCustomer::query()->create([
            'name' => 'B2C No Address',
            'phone' => '9000001002',
        ]);

        $this->createCompletedSale($customer, [
            'buyer_gstin' => null,
            'billing_address' => null,
            'billing_address_structured' => null,
            'place_of_supply_state' => 'Delhi',
        ]);

        $this->actingAs($this->seller)
            ->getJson(route('pos.customers.show', $customer))
            ->assertOk()
            ->assertJsonPath('billing_address', null)
            ->assertJsonPath('billing_city', null)
            ->assertJsonPath('billing_source', PosCustomerLookupService::BILLING_SOURCE_NONE)
            ->assertJsonPath('place_of_supply_state', 'Delhi')
            ->assertJsonPath('place_of_supply_source', PosCustomerLookupService::BILLING_SOURCE_LAST_SALE);
    }

    public function test_b2b_customer_master_gstin_and_last_sale_address(): void
    {
        $customer = InventoryCustomer::query()->create([
            'name' => 'Phil Technologies',
            'phone' => '9820400104',
            'email' => null,
            'gstin' => '27AAICP1128M1Z7',
        ]);

        $this->createCompletedSale($customer, [
            'buyer_gstin' => '27AAICP1128M1Z7',
            'billing_address' => 'G40, Harmony Mall, Link Road, Goregaon',
            'billing_address_structured' => [
                'line1' => 'G40, Harmony Mall, Link Road, Goregaon',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'pincode' => '400104',
            ],
            'place_of_supply_state' => 'Maharashtra',
        ]);

        $this->actingAs($this->seller)
            ->getJson(route('pos.customers.show', $customer))
            ->assertOk()
            ->assertJsonPath('gstin', '27AAICP1128M1Z7')
            ->assertJsonPath('billing_address', 'G40, Harmony Mall, Link Road, Goregaon')
            ->assertJsonPath('billing_city', 'Mumbai')
            ->assertJsonPath('billing_state', 'Maharashtra')
            ->assertJsonPath('billing_pincode', '400104')
            ->assertJsonPath('place_of_supply_state', 'Maharashtra')
            ->assertJsonPath('billing_source', PosCustomerLookupService::BILLING_SOURCE_LAST_SALE);
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
        $this->assertSame(PosCustomerLookupService::BILLING_SOURCE_NONE, $payload['billing_source']);
    }

    public function test_show_does_not_create_a_customer(): void
    {
        $customer = InventoryCustomer::query()->create([
            'name' => 'Stable Count',
            'phone' => '9000001999',
        ]);

        $before = InventoryCustomer::query()->count();

        $this->actingAs($this->seller)
            ->getJson(route('pos.customers.show', $customer))
            ->assertOk()
            ->assertJsonPath('id', $customer->id);

        $this->assertSame($before, InventoryCustomer::query()->count());
        $this->assertSame('Stable Count', $customer->fresh()->name);
    }

    public function test_counter_page_resets_billing_fields_from_lookup_payload(): void
    {
        $html = $this->actingAs($this->seller)
            ->get(route('pos.counter.create', ['branch_id' => $this->branch->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("billingAddressInput.value = data.billing_address || ''", $html);
        $this->assertStringContainsString('placeOfSupplyState.value = data.place_of_supply_state || defaultPlaceOfSupplyState', $html);
        $this->assertStringContainsString('No stored billing address', $html);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCompletedSale(InventoryCustomer $customer, array $overrides): InventorySale
    {
        return InventorySale::query()->create(array_merge([
            'sale_no' => 'POS-TEST-'.$customer->id.'-'.bin2hex(random_bytes(3)),
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'status' => InventorySaleStatus::Completed,
            'subtotal' => 100,
            'discount' => 0,
            'tax' => 18,
            'total' => 118,
            'payment_method' => 'Cash',
            'completed_at' => now(),
        ], $overrides));
    }
}
