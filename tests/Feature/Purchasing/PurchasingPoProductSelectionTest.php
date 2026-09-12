<?php

namespace Tests\Feature\Purchasing;

use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\VendorService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchasingPoProductSelectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Vendor $vendor;

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

        $this->vendor = app(VendorService::class)->create([
            'business_name' => 'PO Vendor',
            'is_active' => true,
        ], $this->admin);
    }

    public function test_product_search_returns_matches_beyond_first_five(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            InventoryProduct::query()->create([
                'sku' => 'RBPO'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'name' => 'PO Product '.$i,
                'gst_percentage' => 18,
                'unit_price' => 1000,
                'is_serialized' => $i % 2 === 0,
                'is_active' => true,
            ]);
        }

        $response = $this->actingAs($this->admin)
            ->getJson(route('purchasing.products.search', ['q' => 'RBPO012']));

        $response->assertOk();
        $response->assertJsonPath('products.0.sku', 'RBPO012');
        $response->assertJsonPath('products.0.is_serialized', true);
    }

    public function test_po_create_accepts_selected_product_beyond_first_five(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            InventoryProduct::query()->create([
                'sku' => 'RBSEL'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'name' => 'Selectable '.$i,
                'gst_percentage' => 18,
                'unit_price' => 500,
                'is_serialized' => false,
                'is_active' => true,
            ]);
        }

        $target = InventoryProduct::query()->where('sku', 'RBSEL008')->firstOrFail();

        $response = $this->actingAs($this->admin)->post(route('purchasing.purchase-orders.store'), [
            'vendor_id' => $this->vendor->id,
            'branch_id' => $this->branch->id,
            'po_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $target->id,
                'quantity' => 3,
                'unit_cost' => 250,
                'tax_rate' => 18,
                'discount_amount' => 0,
            ]],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('purchase_order_items', [
            'product_id' => $target->id,
            'quantity_ordered' => 3,
            'sku' => 'RBSEL008',
        ]);
    }

    public function test_zero_quantity_lines_are_rejected(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'RBZERO',
            'name' => 'Zero Qty',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->from(route('purchasing.purchase-orders.create'))->post(route('purchasing.purchase-orders.store'), [
            'vendor_id' => $this->vendor->id,
            'branch_id' => $this->branch->id,
            'po_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => 0,
                'unit_cost' => 100,
                'tax_rate' => 18,
            ]],
        ]);

        $response->assertRedirect(route('purchasing.purchase-orders.create'));
        $response->assertSessionHasErrors('lines.0.quantity');
    }

    public function test_duplicate_product_lines_are_rejected(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'RBDUP',
            'name' => 'Duplicate',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);

        app(PurchaseOrderService::class)->createDraft([
            'vendor_id' => $this->vendor->id,
            'branch_id' => $this->branch->id,
            'po_date' => now()->toDateString(),
        ], [
            ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 100, 'tax_rate' => 18],
            ['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 100, 'tax_rate' => 18],
        ], $this->admin);
    }

    public function test_unauthorized_user_cannot_search_products(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->getJson(route('purchasing.products.search', ['q' => 'RB']))
            ->assertForbidden();
    }
}
