<?php

namespace Tests\Feature\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\VendorService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PurchasingPoNumberingTest extends TestCase
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
            'business_name' => 'Numbering Vendor',
            'is_active' => true,
        ], $this->admin);
    }

    public function test_http_store_assigns_fy_po_number_and_rejects_client_po_number(): void
    {
        Carbon::setTestNow('2026-09-12 10:00:00');

        $product = InventoryProduct::query()->create([
            'sku' => 'RBPO001',
            'name' => 'Numbered Product',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->post(route('purchasing.purchase-orders.store'), [
            'vendor_id' => $this->vendor->id,
            'branch_id' => $this->branch->id,
            'po_date' => '2026-09-12',
            'po_number' => 'PO-07-999',
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_cost' => 100,
                'tax_rate' => 18,
            ]],
        ]);

        $response->assertSessionHasErrors('po_number');
        $this->assertDatabaseCount('purchase_orders', 0);

        $response = $this->actingAs($this->admin)->post(route('purchasing.purchase-orders.store'), [
            'vendor_id' => $this->vendor->id,
            'branch_id' => $this->branch->id,
            'po_date' => '2026-09-12',
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_cost' => 100,
                'tax_rate' => 18,
            ]],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('purchase_orders', [
            'po_number' => 'PO-07-001',
            'status' => PurchaseOrderStatus::Draft->value,
        ]);
    }

    public function test_serialized_product_does_not_require_serial_on_po(): void
    {
        Carbon::setTestNow('2026-09-12 10:00:00');

        $product = InventoryProduct::query()->create([
            'sku' => 'RBSER001',
            'name' => 'Serialized Product',
            'gst_percentage' => 18,
            'unit_price' => 500,
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $po = app(PurchaseOrderService::class)->createDraft([
            'vendor_id' => $this->vendor->id,
            'branch_id' => $this->branch->id,
            'po_date' => '2026-09-12',
        ], [[
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_cost' => 500,
            'tax_rate' => 18,
        ]], $this->admin);

        $this->assertSame('PO-07-001', $po->po_number);
        $this->assertSame(3, $po->items->first()->quantity_ordered);
    }

    public function test_multiple_lines_calculate_totals(): void
    {
        Carbon::setTestNow('2026-09-12 10:00:00');

        $first = InventoryProduct::query()->create([
            'sku' => 'RBMULTI1',
            'name' => 'Line One',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        $second = InventoryProduct::query()->create([
            'sku' => 'RBMULTI2',
            'name' => 'Line Two',
            'gst_percentage' => 5,
            'unit_price' => 200,
            'is_serialized' => false,
            'is_active' => true,
        ]);

        $po = app(PurchaseOrderService::class)->createDraft([
            'vendor_id' => $this->vendor->id,
            'branch_id' => $this->branch->id,
            'po_date' => '2026-09-12',
        ], [
            ['product_id' => $first->id, 'quantity' => 2, 'unit_cost' => 100, 'tax_rate' => 18],
            ['product_id' => $second->id, 'quantity' => 1, 'unit_cost' => 200, 'tax_rate' => 5],
        ], $this->admin);

        $this->assertCount(2, $po->items);
        $this->assertSame(400.0, (float) $po->subtotal);
        $this->assertSame(46.0, (float) $po->tax_total);
        $this->assertSame(446.0, (float) $po->grand_total);
    }

    public function test_sequential_allocation_produces_unique_numbers(): void
    {
        Carbon::setTestNow('2026-09-12 10:00:00');

        $product = InventoryProduct::query()->create([
            'sku' => 'RBSEQ001',
            'name' => 'Sequential Product',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => false,
            'is_active' => true,
        ]);

        $service = app(PurchaseOrderService::class);
        $header = [
            'vendor_id' => $this->vendor->id,
            'branch_id' => $this->branch->id,
            'po_date' => '2026-09-12',
        ];
        $line = [[
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_cost' => 100,
            'tax_rate' => 18,
        ]];

        $first = $service->createDraft($header, $line, $this->admin);
        $second = $service->createDraft($header, $line, $this->admin);

        $this->assertSame('PO-07-001', $first->po_number);
        $this->assertSame('PO-07-002', $second->po_number);
    }

    public function test_duplicate_po_number_is_rejected_by_database(): void
    {
        PurchaseOrder::query()->create([
            'po_number' => 'PO-07-001',
            'vendor_id' => $this->vendor->id,
            'branch_id' => $this->branch->id,
            'po_date' => '2026-09-12',
            'status' => PurchaseOrderStatus::Draft,
            'created_by_user_id' => $this->admin->id,
            'updated_by_user_id' => $this->admin->id,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        PurchaseOrder::query()->create([
            'po_number' => 'PO-07-001',
            'vendor_id' => $this->vendor->id,
            'branch_id' => $this->branch->id,
            'po_date' => '2026-09-12',
            'status' => PurchaseOrderStatus::Draft,
            'created_by_user_id' => $this->admin->id,
            'updated_by_user_id' => $this->admin->id,
        ]);
    }
}
