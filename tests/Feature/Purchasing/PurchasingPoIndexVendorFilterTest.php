<?php

namespace Tests\Feature\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\VendorService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PurchasingPoIndexVendorFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private InventoryBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->branch = InventoryBranch::query()->create([
            'code' => 'HQ',
            'name' => 'Head Office',
            'is_active' => true,
        ]);
    }

    public function test_index_page_loads_without_static_vendor_dropdown(): void
    {
        $response = $this->actingAs($this->admin)->get(route('purchasing.purchase-orders.index'));

        $response->assertOk();
        $response->assertSee('id="po-index-vendor-search"', false);
        $response->assertDontSee('<select name="vendor_id"', false);
    }

    public function test_vendor_filter_limits_results_by_vendor_id(): void
    {
        Carbon::setTestNow('2026-09-12 10:00:00');

        $vendorA = $this->createVendor('Alpha Supplies');
        $vendorB = $this->createVendor('Beta Components');
        $product = $this->createProduct();

        $poA = $this->createPo($vendorA, $product);
        $this->createPo($vendorB, $product);

        $response = $this->actingAs($this->admin)->get(route('purchasing.purchase-orders.index', [
            'vendor_id' => $vendorA->id,
        ]));

        $response->assertOk();
        $response->assertSee($poA->po_number);
        $response->assertDontSee($vendorB->business_name);
    }

    public function test_invalid_vendor_id_is_ignored_safely(): void
    {
        $response = $this->actingAs($this->admin)->get(route('purchasing.purchase-orders.index', [
            'vendor_id' => 999999,
        ]));

        $response->assertOk();
        $response->assertSee('No purchase orders yet.');
    }

    public function test_po_number_filter_remains_functional(): void
    {
        Carbon::setTestNow('2026-09-12 10:00:00');

        $vendor = $this->createVendor('Gamma Vendor');
        $product = $this->createProduct();
        $po = $this->createPo($vendor, $product);

        $response = $this->actingAs($this->admin)->get(route('purchasing.purchase-orders.index', [
            'q' => 'PO-07-001',
        ]));

        $response->assertOk();
        $response->assertSee($po->po_number);
    }

    public function test_status_filter_remains_functional(): void
    {
        Carbon::setTestNow('2026-09-12 10:00:00');

        $vendor = $this->createVendor('Delta Vendor');
        $product = $this->createProduct();
        $po = $this->createPo($vendor, $product);

        $response = $this->actingAs($this->admin)->get(route('purchasing.purchase-orders.index', [
            'status' => PurchaseOrderStatus::Draft->value,
        ]));

        $response->assertOk();
        $response->assertSee($po->po_number);

        $response = $this->actingAs($this->admin)->get(route('purchasing.purchase-orders.index', [
            'status' => PurchaseOrderStatus::Sent->value,
        ]));

        $response->assertOk();
        $response->assertDontSee($po->po_number);
    }

    public function test_pagination_preserves_vendor_filter_query_string(): void
    {
        Carbon::setTestNow('2026-09-12 10:00:00');

        $vendor = $this->createVendor('Epsilon Vendor');
        $product = $this->createProduct();

        for ($i = 0; $i < 31; $i++) {
            $this->createPo($vendor, $product);
        }

        $response = $this->actingAs($this->admin)->get(route('purchasing.purchase-orders.index', [
            'vendor_id' => $vendor->id,
        ]));

        $response->assertOk();
        $response->assertSee('vendor_id='.$vendor->id, false);
    }

    public function test_inactive_vendors_are_not_searchable_but_existing_filter_still_works(): void
    {
        $active = $this->createVendor('Active Vendor');
        $inactive = app(VendorService::class)->create([
            'business_name' => 'Inactive Vendor',
            'is_active' => false,
        ], $this->admin);

        $this->actingAs($this->admin)
            ->getJson(route('purchasing.vendors.search', ['q' => 'Inactive']))
            ->assertOk()
            ->assertJsonCount(0, 'vendors');

        $response = $this->actingAs($this->admin)->get(route('purchasing.purchase-orders.index', [
            'vendor_id' => $inactive->id,
        ]));

        $response->assertOk();
        $response->assertSee('Inactive Vendor');
    }

    private function createVendor(string $name): Vendor
    {
        return app(VendorService::class)->create([
            'business_name' => $name,
            'is_active' => true,
        ], $this->admin);
    }

    private function createProduct(): InventoryProduct
    {
        return InventoryProduct::query()->create([
            'sku' => 'RBIDX'.uniqid(),
            'name' => 'Index Product',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => false,
            'is_active' => true,
        ]);
    }

    private function createPo(Vendor $vendor, InventoryProduct $product)
    {
        return app(PurchaseOrderService::class)->createDraft([
            'vendor_id' => $vendor->id,
            'branch_id' => $this->branch->id,
            'po_date' => '2026-09-12',
        ], [[
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_cost' => 100,
            'tax_rate' => 18,
        ]], $this->admin);
    }
}
