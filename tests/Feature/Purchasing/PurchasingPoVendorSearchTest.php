<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Services\Purchasing\VendorService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchasingPoVendorSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    public function test_vendor_search_matches_business_name(): void
    {
        $vendor = app(VendorService::class)->create([
            'business_name' => 'ABC Technologies',
            'gstin' => '29ABCDE1234F1Z5',
            'phone' => '9876543210',
            'is_active' => true,
        ], $this->admin);

        $response = $this->actingAs($this->admin)
            ->getJson(route('purchasing.vendors.search', ['q' => 'ABC Tech']));

        $response->assertOk();
        $response->assertJsonPath('vendors.0.id', $vendor->id);
        $response->assertJsonPath('vendors.0.business_name', 'ABC Technologies');
    }

    public function test_vendor_search_matches_gstin(): void
    {
        $vendor = app(VendorService::class)->create([
            'business_name' => 'GST Vendor',
            'gstin' => '07AAAAA0000A1Z5',
            'is_active' => true,
        ], $this->admin);

        $response = $this->actingAs($this->admin)
            ->getJson(route('purchasing.vendors.search', ['q' => '07AAAAA0000A1Z5']));

        $response->assertOk();
        $response->assertJsonPath('vendors.0.id', $vendor->id);
    }

    public function test_store_rejects_missing_vendor_selection(): void
    {
        $response = $this->actingAs($this->admin)
            ->from(route('purchasing.purchase-orders.create'))
            ->post(route('purchasing.purchase-orders.store'), [
                'branch_id' => 1,
                'po_date' => now()->toDateString(),
                'lines' => [[
                    'product_id' => 1,
                    'quantity' => 1,
                    'unit_cost' => 100,
                ]],
            ]);

        $response->assertRedirect(route('purchasing.purchase-orders.create'));
        $response->assertSessionHasErrors('vendor_id');
    }

    public function test_store_rejects_inactive_vendor(): void
    {
        $vendor = app(VendorService::class)->create([
            'business_name' => 'Inactive Vendor',
            'is_active' => false,
        ], $this->admin);

        $response = $this->actingAs($this->admin)
            ->from(route('purchasing.purchase-orders.create'))
            ->post(route('purchasing.purchase-orders.store'), [
                'vendor_id' => $vendor->id,
                'branch_id' => 1,
                'po_date' => now()->toDateString(),
                'lines' => [[
                    'product_id' => 1,
                    'quantity' => 1,
                    'unit_cost' => 100,
                ]],
            ]);

        $response->assertRedirect(route('purchasing.purchase-orders.create'));
        $response->assertSessionHasErrors('vendor_id');
    }

    public function test_unauthorized_user_cannot_search_vendors(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->getJson(route('purchasing.vendors.search', ['q' => 'ABC']))
            ->assertForbidden();
    }
}
