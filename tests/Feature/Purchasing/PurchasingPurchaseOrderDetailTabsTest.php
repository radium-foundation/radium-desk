<?php

namespace Tests\Feature\Purchasing;

use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchasingAuditLog;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchasingPurchaseOrderDetailTabsTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->viewer = User::factory()->create(['is_active' => true]);
        $this->viewer->givePermissionTo([
            RolePermissionSeeder::PERMISSION_PURCHASE_VIEW,
            RolePermissionSeeder::PERMISSION_PURCHASE_EDIT,
            RolePermissionSeeder::PERMISSION_PURCHASE_RECEIVE,
            RolePermissionSeeder::PERMISSION_PURCHASE_INVOICE,
        ]);
    }

    public function test_purchase_order_detail_tabs_are_clickable_bootstrap_tabs(): void
    {
        $po = $this->createPurchaseOrder(PurchaseOrderStatus::Received);

        $response = $this->actingAs($this->viewer)
            ->get(route('purchasing.purchase-orders.show', $po))
            ->assertOk();

        foreach (['details', 'products', 'receiving', 'payments', 'activity'] as $tab) {
            $response->assertSee('id="po-tab-'.$tab.'"', false);
            $response->assertSee('data-bs-toggle="tab"', false);
            $response->assertSee('data-bs-target="#po-pane-'.$tab.'"', false);
            $response->assertSee('id="po-pane-'.$tab.'"', false);
        }

        $this->assertStringNotContainsString('nav-link disabled', (string) $response->getContent());
    }

    public function test_products_tab_shows_line_items(): void
    {
        $po = $this->createPurchaseOrder(PurchaseOrderStatus::Received);

        $response = $this->actingAs($this->viewer)
            ->get(route('purchasing.purchase-orders.show', ['purchaseOrder' => $po, 'tab' => 'products']))
            ->assertOk()
            ->assertSee('RBUGR89GPS')
            ->assertSee('99');

        $html = (string) $response->getContent();
        $this->assertStringContainsString('id="po-pane-products"', $html);
        $this->assertStringContainsString('class="tab-pane fade show active"', $html);
        $this->assertStringContainsString('id="po-tab-products"', $html);
        $this->assertStringContainsString('class="nav-link active"', $html);
    }

    public function test_receiving_tab_lists_goods_receipts(): void
    {
        $po = $this->createPurchaseOrder(PurchaseOrderStatus::Received);
        GoodsReceipt::query()->create([
            'receipt_number' => 'GR-2026-00008',
            'purchase_order_id' => $po->id,
            'status' => GoodsReceiptStatus::Completed,
        ]);

        $response = $this->actingAs($this->viewer)
            ->get(route('purchasing.purchase-orders.show', ['purchaseOrder' => $po, 'tab' => 'receiving']))
            ->assertOk()
            ->assertSee('GR-2026-00008');

        $html = (string) $response->getContent();
        $this->assertStringContainsString('id="po-pane-receiving"', $html);
        $this->assertStringContainsString('class="tab-pane fade show active"', $html);
    }

    public function test_activity_tab_lists_audit_entries(): void
    {
        $po = $this->createPurchaseOrder(PurchaseOrderStatus::Received);
        PurchasingAuditLog::query()->create([
            'user_id' => $this->viewer->id,
            'event' => 'purchase_order.created',
            'auditable_type' => PurchaseOrder::class,
            'auditable_id' => $po->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->viewer)
            ->get(route('purchasing.purchase-orders.show', ['purchaseOrder' => $po, 'tab' => 'activity']))
            ->assertOk()
            ->assertSee('purchase_order.created');

        $html = (string) $response->getContent();
        $this->assertStringContainsString('id="po-pane-activity"', $html);
        $this->assertStringContainsString('class="tab-pane fade show active"', $html);
    }

    public function test_payments_tab_shows_empty_state_when_no_supplier_invoices(): void
    {
        $po = $this->createPurchaseOrder(PurchaseOrderStatus::Received);

        $response = $this->actingAs($this->viewer)
            ->get(route('purchasing.purchase-orders.show', ['purchaseOrder' => $po, 'tab' => 'payments']))
            ->assertOk()
            ->assertSee('No supplier invoices recorded for this purchase order.');

        $html = (string) $response->getContent();
        $this->assertStringContainsString('id="po-pane-payments"', $html);
        $this->assertStringContainsString('class="tab-pane fade show active"', $html);
    }

    public function test_received_purchase_order_does_not_offer_edit_action(): void
    {
        $po = $this->createPurchaseOrder(PurchaseOrderStatus::Received);

        $this->actingAs($this->viewer)
            ->get(route('purchasing.purchase-orders.show', $po))
            ->assertOk()
            ->assertDontSee('Edit PO')
            ->assertSee('can no longer be edited');
    }

    public function test_draft_purchase_order_offers_release_but_not_edit(): void
    {
        $po = $this->createPurchaseOrder(PurchaseOrderStatus::Draft);

        $this->actingAs($this->viewer)
            ->get(route('purchasing.purchase-orders.show', $po))
            ->assertOk()
            ->assertSee('Release PO')
            ->assertDontSee('Edit PO');
    }

    public function test_update_draft_route_is_not_registered(): void
    {
        $this->assertFalse(Route::has('purchasing.purchase-orders.edit'));
        $this->assertFalse(Route::has('purchasing.purchase-orders.update'));
    }

    private function createPurchaseOrder(PurchaseOrderStatus $status): PurchaseOrder
    {
        $this->ensurePurchasingTables();

        $vendor = Vendor::query()->create([
            'business_name' => 'Black Box GPS Technology OPC P Ltd.',
            'gstin' => '04AAGCB4202Q2ZP',
            'is_active' => true,
        ]);
        $branch = InventoryBranch::query()->create([
            'code' => 'DELHI',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);
        $product = InventoryProduct::query()->create([
            'sku' => 'RBUGR89GPS',
            'name' => 'UGR89 GPS',
            'gst_percentage' => 18,
            'is_active' => true,
        ]);
        $po = PurchaseOrder::query()->create([
            'po_number' => 'PO-2026-00002',
            'vendor_id' => $vendor->id,
            'branch_id' => $branch->id,
            'po_date' => '2026-09-18',
            'status' => $status,
            'subtotal' => 0,
            'tax_total' => 0,
            'grand_total' => 0,
            'sent_at' => $status === PurchaseOrderStatus::Draft ? null : now(),
        ]);
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'quantity_ordered' => 99,
            'quantity_received' => $status === PurchaseOrderStatus::Received ? 99 : 0,
            'unit_cost' => 0,
            'tax_rate' => 18,
            'discount_amount' => 0,
            'line_total' => 0,
        ]);

        return $po;
    }

    private function ensurePurchasingTables(): void
    {
        if (! Schema::hasTable('vendors')) {
            Schema::create('vendors', function (Blueprint $table): void {
                $table->id();
                $table->string('business_name');
                $table->string('gstin')->nullable();
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
                $table->decimal('discount_total', 12, 2)->default(0);
                $table->decimal('grand_total', 12, 2)->default(0);
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
                $table->date('invoice_date')->nullable();
                $table->string('payment_status')->nullable();
                $table->decimal('invoice_amount', 12, 2)->default(0);
                $table->decimal('amount_paid', 12, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('purchase_payments')) {
            Schema::create('purchase_payments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('supplier_invoice_id');
                $table->date('payment_date')->nullable();
                $table->string('payment_method')->nullable();
                $table->string('transaction_reference')->nullable();
                $table->decimal('amount', 12, 2)->default(0);
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
                $table->timestamp('created_at')->nullable();
            });
        }
    }
}
