<?php

namespace Tests\Feature\Pos;

use App\Enums\InventorySaleStatus;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServiceQuoteStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\InventorySale;
use App\Models\InventoryUserBranch;
use App\Models\ServiceOrder;
use App\Models\ServiceQuote;
use App\Models\User;
use App\Services\Pos\PosCustomerLookupService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PosCustomerLookupBillingSnapshotTest extends TestCase
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

    public function test_show_populates_billing_from_service_quote_only_history(): void
    {
        $customer = $this->createCustomer();

        $this->createServiceQuote($customer, [
            'billing_address' => 'Service Park Road',
            'billing_address_structured' => [
                'line1' => 'Service Park Road',
                'city' => 'Coimbatore',
                'state' => 'Tamil Nadu',
                'pincode' => '641107',
            ],
            'billing_state' => 'Tamil Nadu',
            'place_of_supply_state' => 'Tamil Nadu',
        ]);

        $this->actingAs($this->seller)
            ->getJson(route('pos.customers.show', $customer))
            ->assertOk()
            ->assertJsonPath('billing_address', 'Service Park Road')
            ->assertJsonPath('billing_city', 'Coimbatore')
            ->assertJsonPath('billing_state', 'Tamil Nadu')
            ->assertJsonPath('billing_pincode', '641107')
            ->assertJsonPath('place_of_supply_state', 'Tamil Nadu');
    }

    public function test_show_populates_billing_from_service_order_only_history(): void
    {
        $customer = $this->createCustomer();

        $this->createServiceOrder($customer, [
            'billing_address' => 'Order Tower',
            'billing_address_structured' => [
                'line1' => 'Order Tower',
                'city' => 'Bengaluru',
                'state' => 'Karnataka',
                'pincode' => '560001',
            ],
            'billing_state' => 'Karnataka',
            'place_of_supply_state' => 'Karnataka',
        ]);

        $this->actingAs($this->seller)
            ->getJson(route('pos.customers.show', $customer))
            ->assertOk()
            ->assertJsonPath('billing_address', 'Order Tower')
            ->assertJsonPath('billing_city', 'Bengaluru')
            ->assertJsonPath('billing_state', 'Karnataka')
            ->assertJsonPath('billing_pincode', '560001');
    }

    public function test_newer_service_quote_snapshot_wins_over_older_product_sale(): void
    {
        $customer = $this->createCustomer();

        $this->createCompletedSale($customer, [
            'billing_address' => 'Old POS Street',
            'billing_address_structured' => [
                'line1' => 'Old POS Street',
                'city' => 'Delhi',
                'state' => 'Delhi',
                'pincode' => '110001',
            ],
            'place_of_supply_state' => 'Delhi',
        ], Carbon::parse('2026-01-01 10:00:00'));

        $quote = $this->createServiceQuote($customer, [
            'billing_address' => 'New Service Lane',
            'billing_address_structured' => [
                'line1' => 'New Service Lane',
                'city' => 'Chennai',
                'state' => 'Tamil Nadu',
                'pincode' => '600001',
            ],
            'billing_state' => 'Tamil Nadu',
            'place_of_supply_state' => 'Tamil Nadu',
        ]);
        $quote->forceFill(['updated_at' => Carbon::parse('2026-06-01 12:00:00')])->save();

        $payload = app(PosCustomerLookupService::class)->resolveById($customer->id);

        $this->assertSame('New Service Lane', $payload['billing_address']);
        $this->assertSame('Chennai', $payload['billing_city']);
        $this->assertSame('Tamil Nadu', $payload['billing_state']);
    }

    public function test_newer_product_sale_snapshot_wins_over_older_service_quote(): void
    {
        $customer = $this->createCustomer();

        $quote = $this->createServiceQuote($customer, [
            'billing_address' => 'Older Service Lane',
            'billing_address_structured' => [
                'line1' => 'Older Service Lane',
                'city' => 'Chennai',
                'state' => 'Tamil Nadu',
                'pincode' => '600001',
            ],
            'billing_state' => 'Tamil Nadu',
            'place_of_supply_state' => 'Tamil Nadu',
        ]);
        $quote->forceFill(['updated_at' => Carbon::parse('2026-01-01 10:00:00')])->save();

        $this->createCompletedSale($customer, [
            'billing_address' => 'Fresh POS Avenue',
            'billing_address_structured' => [
                'line1' => 'Fresh POS Avenue',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'pincode' => '400001',
            ],
            'place_of_supply_state' => 'Maharashtra',
        ], Carbon::parse('2026-06-01 12:00:00'));

        $payload = app(PosCustomerLookupService::class)->resolveById($customer->id);

        $this->assertSame('Fresh POS Avenue', $payload['billing_address']);
        $this->assertSame('Mumbai', $payload['billing_city']);
        $this->assertSame('Maharashtra', $payload['billing_state']);
    }

    public function test_show_returns_null_billing_when_no_eligible_snapshot_exists(): void
    {
        $customer = $this->createCustomer();

        $this->actingAs($this->seller)
            ->getJson(route('pos.customers.show', $customer))
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('billing_address', null)
            ->assertJsonPath('billing_city', null)
            ->assertJsonPath('billing_state', null)
            ->assertJsonPath('billing_pincode', null)
            ->assertJsonPath('place_of_supply_state', null);
    }

    public function test_newer_vacuous_service_quote_falls_back_to_older_complete_product_sale(): void
    {
        $customer = $this->createCustomer();

        $this->createCompletedSale($customer, [
            'billing_address' => 'Stable POS Street',
            'billing_address_structured' => [
                'line1' => 'Stable POS Street',
                'city' => 'Delhi',
                'state' => 'Delhi',
                'pincode' => '110001',
            ],
            'place_of_supply_state' => 'Delhi',
        ], Carbon::parse('2026-01-01 10:00:00'));

        $quote = $this->createServiceQuote($customer, [
            'billing_address' => null,
            'billing_address_structured' => null,
            'billing_state' => null,
            'place_of_supply_state' => null,
        ]);
        $quote->forceFill(['updated_at' => Carbon::parse('2026-06-01 12:00:00')])->save();

        $payload = app(PosCustomerLookupService::class)->resolveById($customer->id);

        $this->assertSame('Stable POS Street', $payload['billing_address']);
        $this->assertSame('Delhi', $payload['billing_city']);
        $this->assertSame('110001', $payload['billing_pincode']);
    }

    public function test_newest_non_vacuous_partial_snapshot_is_used_as_a_single_coherent_record(): void
    {
        $customer = $this->createCustomer();

        $this->createCompletedSale($customer, [
            'billing_address' => 'Older complete POS',
            'billing_address_structured' => [
                'line1' => 'Older complete POS',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'pincode' => '400001',
            ],
            'place_of_supply_state' => 'Maharashtra',
        ], Carbon::parse('2026-01-01 10:00:00'));

        $quote = $this->createServiceQuote($customer, [
            'billing_address' => null,
            'billing_address_structured' => null,
            'billing_state' => 'Karnataka',
            'place_of_supply_state' => 'Karnataka',
        ]);
        $quote->forceFill(['updated_at' => Carbon::parse('2026-06-01 12:00:00')])->save();

        $payload = app(PosCustomerLookupService::class)->resolveById($customer->id);

        $this->assertNull($payload['billing_address']);
        $this->assertNull($payload['billing_city']);
        $this->assertSame('Karnataka', $payload['billing_state']);
        $this->assertNull($payload['billing_pincode']);
        $this->assertSame('Karnataka', $payload['place_of_supply_state']);
    }

    public function test_only_vacuous_snapshots_return_empty_billing_payload(): void
    {
        $customer = $this->createCustomer();

        $quote = $this->createServiceQuote($customer, [
            'billing_address' => null,
            'billing_state' => null,
            'place_of_supply_state' => null,
        ]);

        $quote->forceFill(['updated_at' => Carbon::parse('2026-06-01 12:00:00')])->save();

        $payload = app(PosCustomerLookupService::class)->resolveById($customer->id);

        $this->assertNull($payload['billing_address']);
        $this->assertNull($payload['billing_state']);
        $this->assertNull($payload['place_of_supply_state']);
    }

    public function test_cancelled_service_order_and_expired_quote_are_excluded(): void
    {
        $customer = $this->createCustomer();

        $this->createServiceOrder($customer, [
            'billing_address' => 'Cancelled order address',
            'billing_address_structured' => [
                'line1' => 'Cancelled order address',
                'city' => 'Kolkata',
                'state' => 'West Bengal',
                'pincode' => '700001',
            ],
            'billing_state' => 'West Bengal',
            'place_of_supply_state' => 'West Bengal',
            'status' => ServiceOrderStatus::Cancelled,
        ]);

        $this->createServiceQuote($customer, [
            'billing_address' => 'Expired quote address',
            'billing_state' => 'Gujarat',
            'place_of_supply_state' => 'Gujarat',
            'status' => ServiceQuoteStatus::Expired,
        ]);

        $payload = app(PosCustomerLookupService::class)->resolveById($customer->id);

        $this->assertNull($payload['billing_address']);
        $this->assertNull($payload['billing_state']);
    }

    private function createCustomer(): InventoryCustomer
    {
        return InventoryCustomer::query()->create([
            'name' => 'Snapshot Customer',
            'phone' => '9000012345',
            'email' => 'snapshot@example.test',
            'gstin' => '33AAECA0303M1Z6',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createServiceQuote(InventoryCustomer $customer, array $overrides = []): ServiceQuote
    {
        return ServiceQuote::query()->create(array_merge([
            'quote_number' => 'SQ-TEST-'.strtoupper(bin2hex(random_bytes(3))),
            'status' => ServiceQuoteStatus::Draft,
            'customer_id' => $customer->id,
            'branch_id' => $this->branch->id,
            'buyer_name' => $customer->name,
            'buyer_phone' => $customer->phone,
            'buyer_email' => $customer->email,
            'buyer_gstin' => $customer->gstin,
            'subtotal' => 100,
            'tax_total' => 18,
            'discount' => 0,
            'total' => 118,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createServiceOrder(InventoryCustomer $customer, array $overrides = []): ServiceOrder
    {
        $quote = $this->createServiceQuote($customer, [
            'status' => ServiceQuoteStatus::Converted,
        ]);

        return ServiceOrder::query()->create(array_merge([
            'order_number' => 'SO-TEST-'.strtoupper(bin2hex(random_bytes(3))),
            'quote_id' => $quote->id,
            'customer_id' => $customer->id,
            'branch_id' => $this->branch->id,
            'buyer_name' => $customer->name,
            'buyer_phone' => $customer->phone,
            'buyer_email' => $customer->email,
            'buyer_gstin' => $customer->gstin,
            'status' => ServiceOrderStatus::Open,
            'subtotal' => 100,
            'tax_total' => 18,
            'discount' => 0,
            'total' => 118,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $billing
     */
    private function createCompletedSale(InventoryCustomer $customer, array $billing, Carbon $completedAt): InventorySale
    {
        return InventorySale::query()->create([
            'sale_no' => 'POS-'.strtoupper(bin2hex(random_bytes(3))),
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'buyer_gstin' => $customer->gstin,
            'billing_address' => $billing['billing_address'],
            'billing_address_structured' => $billing['billing_address_structured'],
            'place_of_supply_state' => $billing['place_of_supply_state'],
            'status' => InventorySaleStatus::Completed,
            'subtotal' => 100,
            'discount' => 0,
            'tax' => 18,
            'total' => 118,
            'payment_method' => 'Cash',
            'completed_at' => $completedAt,
        ]);
    }
}
