<?php

namespace Tests\Feature\Purchasing;

use App\Enums\InventorySerialStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchasePaymentStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySerial;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\LegacyVendorImportService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\PurchasePaymentService;
use App\Services\Purchasing\PurchasingReconciliationService;
use App\Services\Purchasing\SupplierInvoiceService;
use App\Services\Purchasing\VendorService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchasingWorkflowTest extends TestCase
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
            'business_name' => 'Test Vendor Pvt Ltd',
            'gstin' => '07TESTV0000V1Z5',
            'pan' => 'TESTV0000V',
            'is_active' => true,
        ], $this->admin);
    }

    public function test_vendor_create_edit_and_toggle(): void
    {
        $vendor = app(VendorService::class)->create([
            'business_name' => 'Another Vendor',
            'is_active' => true,
        ], $this->admin);

        app(VendorService::class)->update($vendor, ['business_name' => 'Another Vendor Updated'], $this->admin);
        $this->assertDatabaseHas('vendors', ['id' => $vendor->id, 'business_name' => 'Another Vendor Updated']);

        app(VendorService::class)->setActive($vendor, false, $this->admin);
        $this->assertFalse($vendor->fresh()->is_active);
    }

    public function test_legacy_vendor_import_is_idempotent_and_records_conflicts(): void
    {
        $path = database_path('fixtures/legacy_suppliers_sample.json');
        $import = app(LegacyVendorImportService::class);

        $first = $import->importFromJson($path, $this->admin, 'TEST-BATCH-1');
        $this->assertSame(3, $first['imported']);

        $second = $import->importFromJson($path, $this->admin, 'TEST-BATCH-2');
        $this->assertSame(0, $second['imported']);
        $this->assertSame(3, $second['skipped']);

        app(VendorService::class)->create([
            'business_name' => 'Manual Vendor',
            'gstin' => '99ZZZZZ9999Z9Z9',
            'is_active' => true,
        ], $this->admin);

        $conflictFixture = json_encode([[
            'legacy_supplier_id' => 9100,
            'company_name' => 'Conflict Vendor',
            'gstno' => '99ZZZZZ9999Z9Z9',
            'status' => 1,
        ]]);

        $tmp = tempnam(sys_get_temp_dir(), 'legacy-vendors');
        file_put_contents($tmp, $conflictFixture);

        $conflicted = $import->importFromJson($tmp, $this->admin, 'TEST-BATCH-3');
        $this->assertSame(1, $conflicted['conflicted']);
        $this->assertDatabaseHas('vendor_import_conflicts', ['legacy_supplier_id' => 9100]);
    }

    public function test_purchase_order_lifecycle_and_numbering(): void
    {
        $product = $this->serializedProduct();
        $poService = app(PurchaseOrderService::class);

        $po = $poService->createDraft([
            'vendor_id' => $this->vendor->id,
            'branch_id' => $this->branch->id,
            'po_date' => now()->toDateString(),
        ], [[
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_cost' => 1000,
            'tax_rate' => 18,
        ]], $this->admin);

        $this->assertSame('PO-07-001', $po->po_number);
        $this->assertSame(PurchaseOrderStatus::Draft, $po->status);

        $poService->send($po, $this->admin);
        $this->assertSame(PurchaseOrderStatus::Sent, $po->fresh()->status);
    }

    public function test_partial_and_full_receiving_with_serial_gate(): void
    {
        $product = $this->serializedProduct();
        $po = $this->createSentPo($product, 3);

        $receiptService = app(GoodsReceiptService::class);
        $reconciliation = app(PurchasingReconciliationService::class);

        $receipt = $receiptService->createDraft($po, [[
            'purchase_order_item_id' => $po->items->first()->id,
            'product_id' => $product->id,
            'quantity_received' => 2,
        ]], $this->admin);

        $item = $receipt->items->first();
        $receiptService->captureSerials($item, ['SN-001', 'SN-002'], $this->admin);
        $receiptService->submitForConfirmation($receipt->fresh(['items.product', 'items.serials']), $this->admin);

        $this->assertSame(0, InventorySerial::query()->where('status', InventorySerialStatus::Available)->count());

        $reconciliation->completeReceiving($receipt->fresh(), $this->admin);
        $this->assertSame(2, InventorySerial::query()->where('status', InventorySerialStatus::Available)->count());
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $po->fresh()->status);

        $receipt2 = $receiptService->createDraft($po->fresh(), [[
            'purchase_order_item_id' => $po->items->first()->id,
            'product_id' => $product->id,
            'quantity_received' => 1,
        ]], $this->admin);
        $receiptService->captureSerials($receipt2->items->first(), ['SN-003'], $this->admin);
        $receiptService->submitForConfirmation($receipt2->fresh(['items.product', 'items.serials']), $this->admin);
        $reconciliation->completeReceiving($receipt2->fresh(), $this->admin);

        $this->assertSame(PurchaseOrderStatus::Received, $po->fresh()->status);
    }

    public function test_over_receipt_is_rejected(): void
    {
        $product = $this->serializedProduct();
        $po = $this->createSentPo($product, 1);

        $this->expectException(ValidationException::class);
        app(GoodsReceiptService::class)->createDraft($po, [[
            'purchase_order_item_id' => $po->items->first()->id,
            'product_id' => $product->id,
            'quantity_received' => 5,
        ]], $this->admin);
    }

    public function test_duplicate_and_existing_serials_are_blocked(): void
    {
        $product = $this->serializedProduct();
        $po = $this->createSentPo($product, 2);

        InventorySerial::query()->create([
            'product_id' => $product->id,
            'serial_number' => 'EXISTING-1',
            'branch_id' => $this->branch->id,
            'status' => InventorySerialStatus::Available,
        ]);

        $receipt = app(GoodsReceiptService::class)->createDraft($po, [[
            'purchase_order_item_id' => $po->items->first()->id,
            'product_id' => $product->id,
            'quantity_received' => 2,
        ]], $this->admin);

        $item = $receipt->items->first();
        app(GoodsReceiptService::class)->captureSerials($item, ['NEW-1', 'EXISTING-1'], $this->admin);

        $this->expectException(ValidationException::class);
        app(GoodsReceiptService::class)->submitForConfirmation($receipt->fresh(['items.product', 'items.serials']), $this->admin);
    }

    public function test_supplier_invoice_and_payments(): void
    {
        $product = $this->serializedProduct();
        $po = $this->createSentPo($product, 1);

        $invoice = app(SupplierInvoiceService::class)->record($po, [
            'supplier_invoice_number' => 'SUP-INV-1001',
            'invoice_date' => now()->toDateString(),
            'invoice_amount' => 118000,
        ], $this->admin);

        $payments = app(PurchasePaymentService::class);
        $payments->record($invoice, ['payment_date' => now()->toDateString(), 'amount' => 50000, 'payment_method' => 'NEFT'], $this->admin);
        $this->assertSame(PurchasePaymentStatus::PartiallyPaid, $invoice->fresh()->payment_status);

        $payments->record($invoice, ['payment_date' => now()->toDateString(), 'amount' => 68000, 'payment_method' => 'NEFT'], $this->admin);
        $this->assertSame(PurchasePaymentStatus::Paid, $invoice->fresh()->payment_status);
    }

    public function test_unauthorized_user_cannot_access_purchasing_routes(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('purchasing.purchase-orders.index'))
            ->assertForbidden();
    }

    private function serializedProduct(): InventoryProduct
    {
        return InventoryProduct::query()->create([
            'sku' => 'RBTEST100',
            'name' => 'Test Serialized Device',
            'gst_percentage' => 18,
            'unit_price' => 2500,
            'is_serialized' => true,
            'is_active' => true,
        ]);
    }

    private function createSentPo(InventoryProduct $product, int $qty): PurchaseOrder
    {
        $po = app(PurchaseOrderService::class)->createDraft([
            'vendor_id' => $this->vendor->id,
            'branch_id' => $this->branch->id,
            'po_date' => now()->toDateString(),
        ], [[
            'product_id' => $product->id,
            'quantity' => $qty,
            'unit_cost' => 1000,
            'tax_rate' => 18,
        ]], $this->admin);

        app(PurchaseOrderService::class)->send($po, $this->admin);

        return $po->fresh(['items']);
    }
}
