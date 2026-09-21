<?php

namespace Tests\Feature\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchasingPurchaseOrderActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->editor = User::factory()->create(['is_active' => true]);
        $this->editor->givePermissionTo([
            RolePermissionSeeder::PERMISSION_PURCHASE_VIEW,
            RolePermissionSeeder::PERMISSION_PURCHASE_EDIT,
            RolePermissionSeeder::PERMISSION_PURCHASE_INVOICE,
        ]);
    }

    public function test_draft_purchase_order_show_uses_release_po_action(): void
    {
        $po = $this->createDraftPurchaseOrder();

        $this->actingAs($this->editor)
            ->get(route('purchasing.purchase-orders.show', $po))
            ->assertOk()
            ->assertSee('Release PO')
            ->assertDontSee('Send PO');
    }

    public function test_release_po_marks_purchase_order_sent_without_email(): void
    {
        $po = $this->createDraftPurchaseOrder();

        $this->actingAs($this->editor)
            ->post(route('purchasing.purchase-orders.send', $po))
            ->assertRedirect()
            ->assertSessionHas('status', 'Purchase order released for receiving.');

        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::Sent, $po->status);
        $this->assertNotNull($po->sent_at);
    }

    public function test_supplier_invoice_action_hidden_for_draft_purchase_order(): void
    {
        $po = $this->createDraftPurchaseOrder();

        $this->actingAs($this->editor)
            ->get(route('purchasing.purchase-orders.show', $po))
            ->assertOk()
            ->assertDontSee('Record supplier invoice');
    }

    public function test_supplier_invoice_action_visible_for_sent_purchase_order(): void
    {
        $po = $this->createDraftPurchaseOrder();
        $po->update(['status' => PurchaseOrderStatus::Sent, 'sent_at' => now()]);

        $this->actingAs($this->editor)
            ->get(route('purchasing.purchase-orders.show', $po))
            ->assertOk()
            ->assertSee('Record supplier invoice');
    }

    private function createDraftPurchaseOrder(): PurchaseOrder
    {
        $this->ensurePurchasingTables();

        $vendor = Vendor::query()->create([
            'vendor_code' => 'V-ACTION',
            'business_name' => 'Action Vendor',
            'is_active' => true,
        ]);
        $branch = InventoryBranch::query()->create([
            'code' => 'ACT-BR',
            'name' => 'Action Branch',
            'is_active' => true,
        ]);
        $product = InventoryProduct::query()->create([
            'sku' => 'ACT-PROD',
            'name' => 'Action Product',
            'gst_percentage' => 18,
            'unit_cost' => 50,
            'is_active' => true,
        ]);
        $po = PurchaseOrder::query()->create([
            'po_number' => 'PO-2026-00001',
            'vendor_id' => $vendor->id,
            'branch_id' => $branch->id,
            'po_date' => now()->toDateString(),
            'status' => PurchaseOrderStatus::Draft,
            'subtotal' => 50,
            'tax_total' => 9,
            'grand_total' => 59,
        ]);
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'quantity_ordered' => 1,
            'unit_cost' => 50,
            'tax_rate' => 18,
            'discount_amount' => 0,
            'line_total' => 59,
        ]);

        return $po;
    }

    private function ensurePurchasingTables(): void
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
                $table->foreignId('created_by_user_id')->nullable();
                $table->foreignId('updated_by_user_id')->nullable();
                $table->timestamp('sent_at')->nullable();
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
                $table->foreignId('purchase_order_id');
                $table->string('status');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('supplier_invoices')) {
            Schema::create('supplier_invoices', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('purchase_order_id');
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
