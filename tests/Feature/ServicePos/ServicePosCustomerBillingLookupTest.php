<?php

namespace Tests\Feature\ServicePos;

use App\Enums\ServiceQuoteStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\ServiceItem;
use App\Models\ServiceQuote;
use App\Models\User;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServicePosCustomerBillingLookupTest extends TestCase
{
    use RefreshDatabase;

    private const BUYER_GSTIN = '33AAECA0303M1Z6';

    private User $admin;

    private InventoryBranch $branch;

    private ServiceItem $serviceItem;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-17 14:00:00');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->seed(ServiceCatalogSeeder::class);
        $this->configureLocationSellerIdentity();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'gstin' => '07AAICP1128M1Z9',
            'is_active' => true,
        ]);

        $this->serviceItem = ServiceItem::query()->where('code', 'DEV-RD-1Y')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_01_service_counter_billing_state_has_blank_default_option(): void
    {
        $this->withoutVite();

        $this->actingAs($this->admin)
            ->get(route('service-pos.counter.create'))
            ->assertOk()
            ->assertSee('<option value="" selected>Select billing state</option>', false);
    }

    public function test_lookup_billing_populates_quote_structured_snapshot_on_store(): void
    {
        $customer = InventoryCustomer::query()->create([
            'name' => 'Repeat Service Buyer',
            'phone' => '9704890700',
            'email' => 'repeat-service@example.test',
            'gstin' => self::BUYER_GSTIN,
        ]);

        ServiceQuote::query()->create([
            'quote_number' => 'SQ-HIST-001',
            'status' => ServiceQuoteStatus::Draft,
            'customer_id' => $customer->id,
            'branch_id' => $this->branch->id,
            'buyer_name' => $customer->name,
            'buyer_phone' => $customer->phone,
            'buyer_email' => $customer->email,
            'buyer_gstin' => self::BUYER_GSTIN,
            'billing_address' => 'SF NO. 384/9A CODEA PARK ROAD',
            'billing_address_structured' => [
                'line1' => 'SF NO. 384/9A CODEA PARK ROAD',
                'city' => 'Coimbatore',
                'state' => 'Tamil Nadu',
                'pincode' => '641107',
            ],
            'billing_state' => 'Tamil Nadu',
            'place_of_supply_state' => 'Tamil Nadu',
            'subtotal' => 100,
            'tax_total' => 18,
            'discount' => 0,
            'total' => 118,
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('pos.customers.show', $customer))
            ->assertOk()
            ->assertJsonPath('billing_city', 'Coimbatore')
            ->assertJsonPath('billing_pincode', '641107');

        $this->actingAs($this->admin)->post(route('service-pos.quotes.store'), [
            'branch_id' => $this->branch->id,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'customer_email' => $customer->email,
            'buyer_gstin' => self::BUYER_GSTIN,
            'billing_address' => 'SF NO. 384/9A CODEA PARK ROAD',
            'billing_city' => 'Coimbatore',
            'billing_state' => 'Tamil Nadu',
            'billing_pincode' => '641107',
            'place_of_supply_state' => 'Tamil Nadu',
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [
                ['service_item_id' => $this->serviceItem->id, 'qty' => 1],
            ],
        ])->assertRedirect();

        $quote = ServiceQuote::query()->where('quote_number', 'like', 'SQ-%')->latest('id')->firstOrFail();
        $this->assertSame('Coimbatore', $quote->billing_address_structured['city'] ?? null);
        $this->assertSame('641107', $quote->billing_address_structured['pincode'] ?? null);
        $this->assertSame('Tamil Nadu', $quote->billing_state);
    }

    public function test_99_b2b_quote_still_requires_city_and_pin(): void
    {
        $this->actingAs($this->admin)->post(route('service-pos.quotes.store'), [
            'branch_id' => $this->branch->id,
            'customer_name' => 'B2B Missing PIN',
            'customer_phone' => '9704890621',
            'buyer_gstin' => self::BUYER_GSTIN,
            'billing_address' => 'Some address',
            'billing_state' => 'Tamil Nadu',
            'place_of_supply_state' => 'Tamil Nadu',
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [
                ['service_item_id' => $this->serviceItem->id, 'qty' => 1],
            ],
        ])->assertSessionHasErrors(['billing_city', 'billing_pincode']);
    }
}
