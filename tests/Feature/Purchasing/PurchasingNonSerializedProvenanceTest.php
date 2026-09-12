<?php

namespace Tests\Feature\Purchasing;

use App\Enums\GoodsReceiptStatus;
use App\Enums\InventoryMovementType;
use App\Models\GoodsReceipt;
use App\Models\InventoryBranch;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\PurchasingReconciliationService;
use App\Services\Purchasing\VendorService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchasingNonSerializedProvenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_serialized_receiving_records_goods_receipt_on_movement(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $branch = InventoryBranch::query()->create([
            'code' => 'HQ',
            'name' => 'Head Office',
            'is_active' => true,
        ]);

        $vendor = app(VendorService::class)->create([
            'business_name' => 'Qty Vendor',
            'is_active' => true,
        ], $admin);

        $product = InventoryProduct::query()->create([
            'sku' => 'RBQTY100',
            'name' => 'Quantity Product',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => false,
            'is_active' => true,
        ]);

        $po = app(PurchaseOrderService::class)->createDraft([
            'vendor_id' => $vendor->id,
            'branch_id' => $branch->id,
            'po_date' => now()->toDateString(),
        ], [[
            'product_id' => $product->id,
            'quantity' => 5,
            'unit_cost' => 80,
            'tax_rate' => 18,
        ]], $admin);

        app(PurchaseOrderService::class)->send($po, $admin);

        $receipt = app(GoodsReceiptService::class)->createDraft($po->fresh(['items']), [[
            'purchase_order_item_id' => $po->items->first()->id,
            'product_id' => $product->id,
            'quantity_received' => 5,
        ]], $admin);

        app(GoodsReceiptService::class)->submitForConfirmation($receipt->fresh(['items.product', 'items.serials']), $admin);
        app(PurchasingReconciliationService::class)->completeReceiving($receipt->fresh(), $admin);

        $movement = InventoryMovement::query()
            ->where('product_id', $product->id)
            ->where('type', InventoryMovementType::PurchaseReceipt)
            ->first();

        $this->assertNotNull($movement);
        $this->assertSame($receipt->id, $movement->goods_receipt_id);
        $this->assertSame(5, $movement->qty);
    }

    public function test_manual_stock_in_movement_remains_without_goods_receipt_reference(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $branch = InventoryBranch::query()->create([
            'code' => 'HQ',
            'name' => 'Head Office',
            'is_active' => true,
        ]);

        $product = InventoryProduct::query()->create([
            'sku' => 'RBMAN100',
            'name' => 'Manual Stock In',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => false,
            'is_active' => true,
        ]);

        app(\App\Services\Inventory\InventoryStockService::class)->stockInQuantity($product, $branch, 2, $admin);

        $movement = InventoryMovement::query()->where('product_id', $product->id)->first();

        $this->assertNotNull($movement);
        $this->assertSame(InventoryMovementType::StockIn, $movement->type);
        $this->assertNull($movement->goods_receipt_id);
    }
}
