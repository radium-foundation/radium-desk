<?php

namespace Tests\Feature\OperationalReference;

use App\Enums\InventorySaleStatus;
use App\Enums\ServiceQuoteStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\ServiceItem;
use App\Models\ServiceOrder;
use App\Models\ServiceQuote;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\ProductPosReferenceService;
use App\Services\RefundReferenceService;
use App\Services\ServiceOrderReferenceService;
use App\Services\ServicePos\ServiceQuoteService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationalReferenceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->seed(ServiceCatalogSeeder::class);

        config([
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);
    }

    public function test_service_quote_conversion_uses_new_svc_series(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $branch = $this->branch();
        $customer = $this->customer();
        $item = ServiceItem::query()->where('code', 'DEV-RD-1Y')->firstOrFail();

        $quote = app(ServiceQuoteService::class)->createQuote(
            $customer,
            $branch,
            [['service_item_id' => $item->id, 'qty' => 1]],
            $actor,
            billingState: 'Delhi',
            placeOfSupplyState: 'Delhi',
        );

        $order = app(ServiceQuoteService::class)->convertToOrder($quote, $actor);

        $this->assertSame('SVC-671', $order->order_number);
        $this->assertFalse(preg_match('/^SVC-0+\d+$/', $order->order_number) === 1);
        $this->assertSame(ServiceQuoteStatus::Converted, $quote->fresh()->status);
    }

    public function test_duplicate_service_quote_conversion_remains_idempotent_with_new_numbering(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $branch = $this->branch();
        $customer = $this->customer();
        $item = ServiceItem::query()->where('code', 'DEV-RD-1Y')->firstOrFail();
        $quotes = app(ServiceQuoteService::class);

        $quote = $quotes->createQuote(
            $customer,
            $branch,
            [['service_item_id' => $item->id, 'qty' => 1]],
            $actor,
            billingState: 'Delhi',
            placeOfSupplyState: 'Delhi',
        );

        $first = $quotes->convertToOrder($quote, $actor);
        $second = $quotes->convertToOrder($quote->fresh(), $actor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('SVC-671', $first->order_number);
        $this->assertSame(1, ServiceOrder::query()->count());
    }

    public function test_product_pos_complete_sale_uses_new_pos_series_and_preserves_inv_numbering(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $branch = $this->branch();
        $stock = app(InventoryStockService::class);
        $sales = app(PosSaleService::class);

        $product = InventoryProduct::query()->create([
            'sku' => 'POS-REF-1',
            'name' => 'POS Reference Product',
            'price' => 100,
            'gst_percentage' => 18,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        $stock->stockIn($product, $branch, 5, $actor);

        $sale = $sales->completeSale(
            branch: $branch,
            customer: ['name' => 'POS Buyer', 'phone' => '9999901234'],
            lines: [['product_id' => $product->id, 'qty' => 1]],
            paymentMethod: 'Cash',
            actor: $actor,
        );

        $this->assertSame('POS-6720', $sale->sale_no);
        $this->assertFalse(preg_match('/^POS-0+\d+$/', $sale->sale_no) === 1);
        $this->assertMatchesRegularExpression('/^INV-'.$branch->code.'-\d{4}-\d{5}$/', (string) $sale->invoice_number);
        $this->assertSame(InventorySaleStatus::Completed, $sale->status);
    }

    public function test_legacy_product_pos_sale_remains_unchanged_when_new_sales_are_created(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $branch = $this->branch();
        $customer = $this->customer();
        $legacy = InventorySale::query()->create([
            'sale_no' => 'POS-000019',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'status' => InventorySaleStatus::Completed,
            'subtotal' => 100,
            'discount' => 0,
            'tax' => 18,
            'total' => 118,
            'payment_method' => 'Cash',
            'invoice_number' => 'INV-HQ-2026-00019',
            'created_by' => $actor->id,
            'completed_at' => now(),
        ]);

        $product = InventoryProduct::query()->create([
            'sku' => 'POS-REF-2',
            'name' => 'POS Reference Product 2',
            'price' => 100,
            'gst_percentage' => 18,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockIn($product, $branch, 1, $actor);

        app(PosSaleService::class)->completeSale(
            branch: $branch,
            customer: ['name' => 'POS Buyer 2', 'phone' => '9999901235'],
            lines: [['product_id' => $product->id, 'qty' => 1]],
            paymentMethod: 'Cash',
            actor: $actor,
        );

        $this->assertSame('POS-000019', $legacy->fresh()->sale_no);
        $this->assertSame('INV-HQ-2026-00019', $legacy->fresh()->invoice_number);
    }

    public function test_concurrent_allocators_do_not_duplicate_within_each_series(): void
    {
        $refundReferences = [];
        $serviceOrderReferences = [];
        $productPosReferences = [];

        DB::transaction(function () use (&$refundReferences, &$serviceOrderReferences, &$productPosReferences): void {
            for ($index = 0; $index < 5; $index++) {
                $refundReferences[] = app(RefundReferenceService::class)->generate();
                $serviceOrderReferences[] = app(ServiceOrderReferenceService::class)->allocate();
                $productPosReferences[] = app(ProductPosReferenceService::class)->allocate();
            }
        });

        $this->assertSame(5, count(array_unique($refundReferences)));
        $this->assertSame(5, count(array_unique($serviceOrderReferences)));
        $this->assertSame(5, count(array_unique($productPosReferences)));
        $this->assertSame('REF-67319', end($refundReferences));
        $this->assertSame('SVC-675', end($serviceOrderReferences));
        $this->assertSame('POS-6724', end($productPosReferences));
    }

    public function test_refund_reference_unique_constraint_prevents_duplicate_insert(): void
    {
        $user = User::factory()->create();
        $orderId = Order::query()->create([
            'order_id' => 'RD-DUP-REF',
            'serial_number' => 'SN-DUP-REF',
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => 'active',
            'created_by' => $user->id,
        ])->id;

        $payload = [
            'order_id' => $orderId,
            'reference_no' => 'REF-67315',
            'amount' => 100,
            'reason' => 'Duplicate reference protection test.',
            'status' => 'pending',
            'requested_by' => $user->id,
        ];

        RefundRequest::query()->create($payload);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        RefundRequest::query()->create($payload);
    }

    private function branch(): InventoryBranch
    {
        return InventoryBranch::query()->create([
            'code' => 'HQ',
            'name' => 'Head Office',
            'is_active' => true,
        ]);
    }

    private function customer(): InventoryCustomer
    {
        return InventoryCustomer::query()->create([
            'name' => 'Operational Ref Customer',
            'phone' => '9999900'.random_int(100, 999),
        ]);
    }
}
