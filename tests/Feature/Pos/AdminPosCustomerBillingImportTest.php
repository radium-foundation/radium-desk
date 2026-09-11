<?php

namespace Tests\Feature\Pos;

use App\Enums\InventorySaleStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\InventoryCustomerBillingProfile;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\InventoryUserBranch;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\Pos\AdminPosCustomerBillingImporter;
use App\Services\Pos\PosCustomerLookupService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPosCustomerBillingImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_one_historical_address_and_rerun_is_idempotent(): void
    {
        $this->padProtectedCustomerIds();
        $customer = InventoryCustomer::query()->create([
            'name' => 'Imported One Addr',
            'phone' => '9000000701',
        ]);

        $source = [[
            'legacy_user_id' => 701,
            'legacy_order_id' => 5001,
            'phone' => '9000000701',
            'address' => '12 MG Road',
            'district' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560001',
        ]];

        $first = app(AdminPosCustomerBillingImporter::class)->execute($source);
        $this->assertSame(1, $first['counts']['created']);
        $this->assertSame(1, InventoryCustomerBillingProfile::query()->count());
        $this->assertSame(0, InventorySale::query()->count());

        $second = app(AdminPosCustomerBillingImporter::class)->execute($source);
        $this->assertSame(0, $second['counts']['created']);
        $this->assertSame(1, $second['counts']['matched']);
        $this->assertSame(1, InventoryCustomerBillingProfile::query()->count());
        $this->assertSame('12 MG Road', $customer->fresh()->billingProfile?->line1);
        $this->assertSame('Imported One Addr', $customer->fresh()->name);
        $this->assertSame(0, InventorySale::query()->count());
    }

    public function test_multiple_historical_addresses_use_provided_latest_order_only(): void
    {
        $this->padProtectedCustomerIds();
        $customer = InventoryCustomer::query()->create([
            'name' => 'Multi Addr',
            'phone' => '9000000702',
        ]);

        $result = app(AdminPosCustomerBillingImporter::class)->execute([[
            'legacy_user_id' => 702,
            'legacy_order_id' => 9009,
            'phone' => '9000000702',
            'address' => 'Latest Lane',
            'district' => 'Mumbai',
            'state' => 'Maharashtra',
            'pincode' => '400001',
        ]]);

        $this->assertSame(1, $result['counts']['created']);
        $this->assertSame('Latest Lane', $customer->fresh()->billingProfile?->line1);
        $this->assertSame(9009, $customer->fresh()->billingProfile?->source_legacy_order_id);
    }

    public function test_missing_invoiced_snapshot_is_review_and_not_imported(): void
    {
        $this->padProtectedCustomerIds();
        InventoryCustomer::query()->create([
            'name' => 'No Snapshot',
            'phone' => '9000000703',
        ]);

        $result = app(AdminPosCustomerBillingImporter::class)->execute([[
            'legacy_user_id' => 703,
            'legacy_order_id' => null,
            'phone' => '9000000703',
            'address' => 'Should Not Import',
            'district' => 'Delhi',
            'state' => 'Delhi',
            'pincode' => '110001',
        ]]);

        $this->assertSame(0, $result['counts']['created']);
        $this->assertSame(1, $result['counts']['review']);
        $this->assertSame(0, InventoryCustomerBillingProfile::query()->count());
    }

    public function test_protected_desk_customers_are_not_updated(): void
    {
        $protected = InventoryCustomer::query()->create([
            'name' => 'Gate4 B2C Walk-in',
            'phone' => '9000099901',
        ]);
        $this->assertSame(1, $protected->id);

        $result = app(AdminPosCustomerBillingImporter::class)->execute([[
            'legacy_user_id' => 999,
            'legacy_order_id' => 1,
            'phone' => '9000099901',
            'address' => 'Should skip',
            'district' => 'Delhi',
            'state' => 'Delhi',
            'pincode' => '110001',
        ]]);

        $this->assertSame(0, $result['counts']['created']);
        $this->assertSame('protected_desk_customer', $result['rows'][0]['reason']);
        $this->assertNull($protected->fresh()->billingProfile);
    }

    public function test_lookup_uses_imported_profile_then_desk_sale_wins(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        $branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);
        InventoryUserBranch::query()->create([
            'user_id' => $seller->id,
            'branch_id' => $branch->id,
        ]);

        $b2b = InventoryCustomer::query()->create([
            'name' => 'Imported B2B',
            'phone' => '9822000704',
            'gstin' => '27AAICP1128M1Z7',
        ]);
        InventoryCustomerBillingProfile::query()->create([
            'customer_id' => $b2b->id,
            'line1' => 'G40 Harmony Mall',
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'pincode' => '400104',
            'source' => InventoryCustomerBillingProfile::SOURCE_LAST_ADMIN_POS_ORDER,
            'source_legacy_order_id' => 1,
        ]);

        $this->actingAs($seller)
            ->getJson(route('pos.customers.show', $b2b))
            ->assertOk()
            ->assertJsonPath('billing_address', 'G40 Harmony Mall')
            ->assertJsonPath('billing_city', 'Mumbai')
            ->assertJsonPath('billing_state', 'Maharashtra')
            ->assertJsonPath('billing_pincode', '400104')
            ->assertJsonPath('place_of_supply_state', 'Maharashtra')
            ->assertJsonPath('billing_source', PosCustomerLookupService::BILLING_SOURCE_IMPORTED_PROFILE);

        InventorySale::query()->create([
            'sale_no' => 'POS-TEST-SALE',
            'branch_id' => $branch->id,
            'customer_id' => $b2b->id,
            'billing_address' => 'Desk sale address',
            'billing_address_structured' => [
                'line1' => 'Desk sale address',
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

        $this->actingAs($seller)
            ->getJson(route('pos.customers.show', $b2b))
            ->assertOk()
            ->assertJsonPath('billing_address', 'Desk sale address')
            ->assertJsonPath('billing_source', PosCustomerLookupService::BILLING_SOURCE_LAST_SALE)
            ->assertJsonPath('place_of_supply_state', 'Delhi');
    }

    public function test_b2c_imported_profile_does_not_set_place_of_supply(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        $branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);
        InventoryUserBranch::query()->create([
            'user_id' => $seller->id,
            'branch_id' => $branch->id,
        ]);

        $b2c = InventoryCustomer::query()->create([
            'name' => 'Imported B2C',
            'phone' => '9000000705',
            'gstin' => null,
        ]);
        InventoryCustomerBillingProfile::query()->create([
            'customer_id' => $b2c->id,
            'line1' => 'Walk-in street',
            'city' => 'Lucknow',
            'state' => 'Uttar Pradesh',
            'pincode' => '226001',
            'source' => InventoryCustomerBillingProfile::SOURCE_LAST_ADMIN_POS_ORDER,
            'source_legacy_order_id' => 2,
        ]);

        $this->actingAs($seller)
            ->getJson(route('pos.customers.show', $b2c))
            ->assertOk()
            ->assertJsonPath('billing_city', 'Lucknow')
            ->assertJsonPath('place_of_supply_state', null)
            ->assertJsonPath('billing_source', PosCustomerLookupService::BILLING_SOURCE_IMPORTED_PROFILE);

        $this->assertSame(0, preg_match('/radiumbox_prod/', (string) file_get_contents(app_path('Services/Pos/PosCustomerLookupService.php'))));
    }

    public function test_foreign_state_is_dropped_and_invalid_pin_is_null(): void
    {
        $this->padProtectedCustomerIds();
        $customer = InventoryCustomer::query()->create([
            'name' => 'Foreign State',
            'phone' => '9000000706',
        ]);

        app(AdminPosCustomerBillingImporter::class)->execute([[
            'legacy_user_id' => 706,
            'legacy_order_id' => 12,
            'phone' => '9000000706',
            'address' => '1 Queen St',
            'district' => 'Auckland',
            'state' => 'Auckland',
            'pincode' => 'ABC',
        ]]);

        $profile = $customer->fresh()->billingProfile;
        $this->assertNotNull($profile);
        $this->assertSame('1 Queen St', $profile->line1);
        $this->assertSame('Auckland', $profile->city);
        $this->assertNull($profile->state);
        $this->assertNull($profile->pincode);
    }

    public function test_switching_customers_replaces_every_billing_field(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        $branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);
        InventoryUserBranch::query()->create([
            'user_id' => $seller->id,
            'branch_id' => $branch->id,
        ]);

        $withProfile = InventoryCustomer::query()->create([
            'name' => 'Historical B2B',
            'phone' => '9822000710',
            'email' => 'hist@example.test',
            'gstin' => '27AAICP1128M1Z7',
        ]);
        InventoryCustomerBillingProfile::query()->create([
            'customer_id' => $withProfile->id,
            'line1' => 'G40 Harmony Mall',
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'pincode' => '400104',
            'source' => InventoryCustomerBillingProfile::SOURCE_LAST_ADMIN_POS_ORDER,
            'source_legacy_order_id' => 10,
        ]);
        $walkIn = InventoryCustomer::query()->create([
            'name' => 'Gate4 B2C Walk-in',
            'phone' => '9000099901',
        ]);

        $this->actingAs($seller)
            ->getJson(route('pos.customers.show', $withProfile))
            ->assertOk()
            ->assertJsonPath('gstin', '27AAICP1128M1Z7')
            ->assertJsonPath('billing_city', 'Mumbai')
            ->assertJsonPath('place_of_supply_state', 'Maharashtra');

        $this->actingAs($seller)
            ->getJson(route('pos.customers.show', $walkIn))
            ->assertOk()
            ->assertJsonPath('gstin', null)
            ->assertJsonPath('billing_address', null)
            ->assertJsonPath('billing_city', null)
            ->assertJsonPath('billing_state', null)
            ->assertJsonPath('billing_pincode', null)
            ->assertJsonPath('place_of_supply_state', null)
            ->assertJsonPath('billing_source', PosCustomerLookupService::BILLING_SOURCE_NONE);
    }

    public function test_completing_sale_snapshots_edited_billing_and_does_not_rewrite_profile(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $actor = User::factory()->create(['is_active' => true]);
        $branch = InventoryBranch::query()->create([
            'code' => 'HQ',
            'name' => 'Head Office',
            'is_active' => true,
        ]);
        $this->padProtectedCustomerIds();
        $customer = InventoryCustomer::query()->create([
            'name' => 'Imported Profile',
            'phone' => '9000000711',
            'gstin' => null,
        ]);
        InventoryCustomerBillingProfile::query()->create([
            'customer_id' => $customer->id,
            'line1' => 'Historical Lane',
            'city' => 'Ujjain',
            'state' => 'Madhya Pradesh',
            'pincode' => '456001',
            'source' => InventoryCustomerBillingProfile::SOURCE_LAST_ADMIN_POS_ORDER,
            'source_legacy_order_id' => 99,
        ]);

        $product = InventoryProduct::query()->create([
            'sku' => 'P237-NONSER',
            'name' => 'Non-serial test SKU',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInQuantity($product, $branch, 5, $actor);

        $sale = app(PosSaleService::class)->completeSale(
            branch: $branch,
            customer: [
                'name' => 'Imported Profile',
                'phone' => '9000000711',
                'gstin' => null,
            ],
            lines: [['product_id' => $product->id, 'qty' => 1]],
            paymentMethod: 'Cash',
            actor: $actor,
            statutory: [
                'billing_address' => 'Edited current sale street',
                'billing_city' => 'New Delhi',
                'billing_state' => 'Delhi',
                'billing_pincode' => '110001',
                'place_of_supply_state' => 'Delhi',
            ],
        );

        $this->assertSame('Edited current sale street', $sale->billing_address);
        $this->assertSame('New Delhi', $sale->billing_address_structured['city'] ?? null);
        $this->assertSame('Delhi', $sale->place_of_supply_state);
        $profile = $customer->fresh()->billingProfile;
        $this->assertSame('Historical Lane', $profile?->line1);
        $this->assertSame('Ujjain', $profile?->city);
        $this->assertSame('456001', $profile?->pincode);
        $this->assertSame('Imported Profile', $customer->fresh()->name);
        $this->assertNull($customer->fresh()->gstin);
    }

    public function test_command_dry_run_does_not_write(): void
    {
        $this->padProtectedCustomerIds();
        InventoryCustomer::query()->create([
            'name' => 'Command User',
            'phone' => '9000000712',
        ]);
        $path = sys_get_temp_dir().'/admin-pos-billing-'.uniqid().'.json';
        file_put_contents($path, json_encode([[
            'legacy_user_id' => 712,
            'legacy_order_id' => 1,
            'phone' => '9000000712',
            'address' => 'Dry run street',
            'district' => 'Delhi',
            'state' => 'Delhi',
            'pincode' => '110001',
        ]]));

        $this->artisan('desk:import-admin-pos-customer-billing', [
            'source' => $path,
            '--dry-run' => true,
        ])->assertOk()
            ->expectsOutputToContain('created=1');

        $this->assertSame(0, InventoryCustomerBillingProfile::query()->count());
        unlink($path);
    }

    private function padProtectedCustomerIds(): void
    {
        foreach ([1, 2, 3, 4, 5, 6] as $i) {
            InventoryCustomer::query()->create([
                'name' => 'Protected pad '.$i,
                'phone' => '800000000'.$i,
            ]);
        }
    }
}
