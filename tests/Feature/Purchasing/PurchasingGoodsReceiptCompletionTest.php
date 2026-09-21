<?php

namespace Tests\Feature\Purchasing;

use App\Enums\GoodsReceiptSerialValidationStatus;
use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\GoodsReceiptSerial;
use App\Models\InventoryBranch;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryStockBalance;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Purchasing\PurchasingReconciliationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchasingGoodsReceiptCompletionTest extends TestCase
{
    use RefreshDatabase;

    private User $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->receiver = User::factory()->create(['is_active' => true]);
        $this->receiver->givePermissionTo([
            RolePermissionSeeder::PERMISSION_PURCHASE_VIEW,
            RolePermissionSeeder::PERMISSION_PURCHASE_RECEIVE,
        ]);
    }

    public function test_complete_receiving_posts_stock_for_serialized_goods_receipt(): void
    {
        $fixtures = $this->createReceivingFixtures(serialized: true);
        $receipt = $this->createPendingReceipt($fixtures, serialNumber: 'SN-PO-GR-001');

        app(PurchasingReconciliationService::class)->completeReceiving($receipt, $this->receiver);

        $receipt->refresh();
        $this->assertSame(GoodsReceiptStatus::Completed, $receipt->status);
        $this->assertDatabaseHas('inventory_serials', [
            'serial_number' => 'SN-PO-GR-001',
            'product_id' => $fixtures['product']->id,
        ]);
        $this->assertSame(1, InventoryMovement::query()->count());
        $this->assertSame(1, (int) InventoryStockBalance::query()->value('available_qty'));
    }

    public function test_complete_receiving_is_idempotent_for_same_receipt(): void
    {
        $fixtures = $this->createReceivingFixtures(serialized: false);
        $receipt = $this->createPendingReceipt($fixtures);

        $service = app(PurchasingReconciliationService::class);
        $service->completeReceiving($receipt, $this->receiver, 'gr-complete-test');
        $service->completeReceiving($receipt->fresh(), $this->receiver, 'gr-complete-test');

        $this->assertSame(1, InventoryMovement::query()->count());
        $this->assertSame(1, (int) InventoryStockBalance::query()->value('available_qty'));
    }

    public function test_http_complete_endpoint_succeeds_for_pending_receipt(): void
    {
        $fixtures = $this->createReceivingFixtures(serialized: false);
        $receipt = $this->createPendingReceipt($fixtures);

        $this->actingAs($this->receiver)
            ->post(route('purchasing.goods-receipts.complete', $receipt))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(GoodsReceiptStatus::Completed, $receipt->fresh()->status);
    }

    /**
     * @return array{vendor: Vendor, branch: InventoryBranch, product: InventoryProduct, purchaseOrder: PurchaseOrder, poItem: PurchaseOrderItem}
     */
    private function createReceivingFixtures(bool $serialized): array
    {
        $this->ensurePurchasingAndInventoryTables();

        $vendor = Vendor::query()->create([
            'vendor_code' => 'V-GR',
            'business_name' => 'GR Vendor',
            'is_active' => true,
        ]);
        $branch = InventoryBranch::query()->create([
            'code' => 'GR-BR',
            'name' => 'GR Branch',
            'is_active' => true,
        ]);
        $product = InventoryProduct::query()->create([
            'sku' => $serialized ? 'SER-GR-1' : 'QTY-GR-1',
            'name' => 'GR Product',
            'gst_percentage' => 18,
            'unit_cost' => 100,
            'is_active' => true,
            'is_serialized' => $serialized,
            'tracks_quantity' => ! $serialized,
        ]);
        $purchaseOrder = PurchaseOrder::query()->create([
            'po_number' => 'PO-2026-00099',
            'vendor_id' => $vendor->id,
            'branch_id' => $branch->id,
            'po_date' => now()->toDateString(),
            'status' => PurchaseOrderStatus::Sent,
            'subtotal' => 100,
            'tax_total' => 18,
            'grand_total' => 118,
        ]);
        $poItem = PurchaseOrderItem::query()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'quantity_ordered' => 2,
            'quantity_received' => 0,
            'unit_cost' => 100,
            'tax_rate' => 18,
            'discount_amount' => 0,
            'line_total' => 118,
        ]);

        return compact('vendor', 'branch', 'product', 'purchaseOrder', 'poItem');
    }

    private function createPendingReceipt(array $fixtures, ?string $serialNumber = null): GoodsReceipt
    {
        $receipt = GoodsReceipt::query()->create([
            'receipt_number' => 'GR-TEST-001',
            'receipt_date' => now()->toDateString(),
            'purchase_order_id' => $fixtures['purchaseOrder']->id,
            'vendor_id' => $fixtures['vendor']->id,
            'branch_id' => $fixtures['branch']->id,
            'status' => GoodsReceiptStatus::PendingConfirmation,
            'received_by_user_id' => $this->receiver->id,
        ]);

        $item = GoodsReceiptItem::query()->create([
            'goods_receipt_id' => $receipt->id,
            'purchase_order_item_id' => $fixtures['poItem']->id,
            'product_id' => $fixtures['product']->id,
            'quantity_received' => 1,
            'quantity_damaged' => 0,
            'quantity_short' => 1,
        ]);

        if ($serialNumber !== null) {
            GoodsReceiptSerial::query()->create([
                'goods_receipt_id' => $receipt->id,
                'goods_receipt_item_id' => $item->id,
                'purchase_order_id' => $fixtures['purchaseOrder']->id,
                'vendor_id' => $fixtures['vendor']->id,
                'product_id' => $fixtures['product']->id,
                'serial_number' => $serialNumber,
                'validation_status' => GoodsReceiptSerialValidationStatus::Valid,
            ]);
        }

        return $receipt->fresh(['items.product', 'items.serials', 'purchaseOrder', 'branch', 'vendor']);
    }

    private function ensurePurchasingAndInventoryTables(): void
    {
        if (! Schema::hasTable('vendors')) {
            Schema::create('vendors', function (Blueprint $table): void {
                $table->id();
                $table->string('vendor_code')->nullable();
                $table->string('business_name');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('purchase_orders')) {
            Schema::create('purchase_orders', function (Blueprint $table): void {
                $table->id();
                $table->string('po_number')->unique();
                $table->foreignId('vendor_id');
                $table->foreignId('branch_id');
                $table->date('po_date');
                $table->string('status');
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('tax_total', 12, 2)->default(0);
                $table->decimal('grand_total', 12, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('purchase_order_items')) {
            Schema::create('purchase_order_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('purchase_order_id');
                $table->foreignId('product_id');
                $table->string('sku');
                $table->unsignedInteger('quantity_ordered');
                $table->unsignedInteger('quantity_received')->default(0);
                $table->decimal('unit_cost', 12, 2);
                $table->decimal('tax_rate', 8, 2)->default(0);
                $table->decimal('discount_amount', 12, 2)->default(0);
                $table->decimal('line_total', 12, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('goods_receipts')) {
            Schema::create('goods_receipts', function (Blueprint $table): void {
                $table->id();
                $table->string('receipt_number')->unique();
                $table->date('receipt_date');
                $table->foreignId('purchase_order_id');
                $table->foreignId('vendor_id');
                $table->foreignId('branch_id');
                $table->string('status');
                $table->foreignId('received_by_user_id')->nullable();
                $table->foreignId('completed_by_user_id')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->string('completion_idempotency_key')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('goods_receipt_items')) {
            Schema::create('goods_receipt_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('goods_receipt_id');
                $table->foreignId('purchase_order_item_id');
                $table->foreignId('product_id');
                $table->unsignedInteger('quantity_received');
                $table->unsignedInteger('quantity_damaged')->default(0);
                $table->unsignedInteger('quantity_short')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('goods_receipt_serials')) {
            Schema::create('goods_receipt_serials', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('goods_receipt_id');
                $table->foreignId('goods_receipt_item_id');
                $table->foreignId('purchase_order_id');
                $table->foreignId('vendor_id');
                $table->foreignId('product_id');
                $table->string('serial_number');
                $table->string('validation_status');
                $table->foreignId('inventory_serial_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('supplier_invoices')) {
            Schema::create('supplier_invoices', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('purchase_order_id');
                $table->foreignId('goods_receipt_id')->nullable();
                $table->string('supplier_invoice_number')->nullable();
                $table->decimal('invoice_amount', 12, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('purchasing_audit_logs')) {
            Schema::create('purchasing_audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable();
                $table->string('event');
                $table->string('auditable_type');
                $table->unsignedBigInteger('auditable_id');
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->string('ip_address')->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }
}
