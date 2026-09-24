<?php

namespace Tests\Feature\ServicePos;

use App\Enums\ServiceOrderPaymentStatus;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServiceQuoteStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\InventorySale;
use App\Models\InventorySaleLine;
use App\Models\InventorySaleSerial;
use App\Models\InventorySerial;
use App\Models\InventoryStockBalance;
use App\Models\PaymentAllocation;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\ServiceOrder;
use App\Models\ServiceQuote;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ServicePos\ServicePaymentService;
use App\Services\ServicePos\ServiceQuoteService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServicePosFoundationTest extends TestCase
{
    use RefreshDatabase;

    private ServiceQuoteService $quotes;

    private StatutoryInvoiceService $invoices;

    private ServicePaymentService $payments;

    private User $actor;

    private InventoryBranch $branch;

    private InventoryCustomer $customer;

    private ServiceItem $rdItem;

    private ServiceItem $amcItem;

    private ServiceItem $customItem;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-17 12:00:00');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->seed(ServiceCatalogSeeder::class);
        $this->configureLocationSellerIdentity();

        config([
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);

        $this->quotes = app(ServiceQuoteService::class);
        $this->invoices = app(StatutoryInvoiceService::class);
        $this->payments = app(ServicePaymentService::class);
        $this->actor = User::factory()->create(['is_active' => true]);

        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'gstin' => '07AAICP1128M1Z9',
            'is_active' => true,
        ]);

        $this->customer = InventoryCustomer::query()->create([
            'name' => 'Service Customer',
            'phone' => '9999900001',
            'email' => 'service@example.test',
        ]);

        $this->rdItem = ServiceItem::query()->where('code', 'DEV-RD-1Y')->firstOrFail();
        $this->amcItem = ServiceItem::query()->where('code', 'DEV-AMC-1Y')->firstOrFail();
        $this->customItem = ServiceItem::query()->where('code', 'DEV-CUSTOM-MS')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_service_category_and_item_creation(): void
    {
        $category = ServiceCategory::query()->where('code', 'rd_service')->firstOrFail();

        $this->assertTrue($category->is_active);
        $this->assertSame(1, $category->legacy_admin_attribute_id);
        $this->assertSame('998313', $this->rdItem->sac_code);
    }

    public function test_inactive_service_cannot_be_sold(): void
    {
        $inactive = ServiceItem::query()->create([
            'category_id' => $this->rdItem->category_id,
            'code' => 'DEV-INACTIVE',
            'name' => 'Inactive Service',
            'sac_code' => '998313',
            'gst_rate' => 18,
            'price_ex_gst' => 100,
            'is_active' => false,
        ]);

        $this->expectException(ValidationException::class);

        $this->quotes->createQuote(
            $this->customer,
            $this->branch,
            [['service_item_id' => $inactive->id]],
            $this->actor,
            billingState: 'Delhi',
            placeOfSupplyState: 'Delhi',
        );
    }

    public function test_quote_preserves_sac_gst_and_price_snapshots(): void
    {
        $quote = $this->createThreeLineQuote();

        $this->assertCount(3, $quote->lines);
        $this->assertSame('998313', $quote->lines[0]->sac_code);
        $this->assertSame('18.00', $quote->lines[0]->gst_rate);
        $this->assertSame('422.88', $quote->lines[0]->unit_price_ex_gst);
        $this->assertSame('998596', $quote->lines[2]->sac_code);
        $this->assertGreaterThan(0, (float) $quote->total);
    }

    public function test_quote_create_idempotency_key_returns_existing_quote(): void
    {
        $first = $this->quotes->createQuote(
            $this->customer,
            $this->branch,
            [['service_item_id' => $this->rdItem->id, 'qty' => 1]],
            $this->actor,
            billingState: 'Delhi',
            placeOfSupplyState: 'Delhi',
            idempotencyKey: 'service-pos-quote-idem',
        );

        $second = $this->quotes->createQuote(
            $this->customer,
            $this->branch,
            [['service_item_id' => $this->rdItem->id, 'qty' => 1]],
            $this->actor,
            billingState: 'Delhi',
            placeOfSupplyState: 'Delhi',
            idempotencyKey: 'service-pos-quote-idem',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ServiceQuote::query()->count());
    }

    public function test_quote_conversion_creates_exactly_one_service_order(): void
    {
        $quote = $this->createThreeLineQuote();
        $order = $this->quotes->convertToOrder($quote, $this->actor);

        $this->assertSame(1, ServiceOrder::query()->count());
        $this->assertSame($quote->id, $order->quote_id);
        $this->assertSame('SVC-671', $order->order_number);
        $this->assertDoesNotMatchRegularExpression('/^SVC-0+\d+$/', $order->order_number);
        $this->assertSame(ServiceQuoteStatus::Converted, $quote->fresh()->status);
        $this->assertCount(3, $order->lines);
    }

    public function test_duplicate_conversion_is_idempotent(): void
    {
        $quote = $this->createThreeLineQuote();
        $first = $this->quotes->convertToOrder($quote, $this->actor);
        $second = $this->quotes->convertToOrder($quote->fresh(), $this->actor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ServiceOrder::query()->count());
    }

    public function test_service_invoice_issues_with_correct_source_and_sac(): void
    {
        $order = $this->serviceOrderFromQuote();
        $invoice = $this->invoices->issueFromServiceOrder($order, $this->actor);

        $this->assertSame(StatutoryInvoiceChannel::DeskService, $invoice->channel);
        $this->assertSame(StatutoryInvoiceSourceType::ServiceOrder->value, $invoice->source_type);
        $this->assertSame($order->order_number, $invoice->source_id);
        $this->assertNull($invoice->inventory_sale_id);
        $this->assertCount(3, $invoice->items);
        $this->assertSame('998313', $invoice->items[0]->hsn_sac);
        $this->assertSame('INV-671', $invoice->invoice_number);
        $this->assertSame(ServiceOrderStatus::Invoiced, $order->fresh()->status);
    }

    public function test_duplicate_invoice_issuance_is_idempotent(): void
    {
        $order = $this->serviceOrderFromQuote();
        $first = $this->invoices->issueFromServiceOrder($order, $this->actor);
        $second = $this->invoices->issueFromServiceOrder($order->fresh(), $this->actor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    public function test_partial_and_full_payment_status_transitions(): void
    {
        $order = $this->serviceOrderFromQuote();
        $invoice = $this->invoices->issueFromServiceOrder($order, $this->actor);
        $total = (float) $invoice->invoice_value;

        $this->assertSame(ServiceOrderPaymentStatus::Unpaid, $order->fresh()->payment_status);

        $paymentA = $this->payments->recordPayment(
            $this->customer,
            round($total * 0.4, 2),
            'Bank Transfer',
            now(),
            $this->actor,
            reference: 'NEFT-SVC-PART-001',
            bankName: 'HDFC Bank',
            bankBranch: 'Connaught Place',
            idempotencyKey: 'pay-a',
        );

        $this->payments->allocatePayment($paymentA, $invoice, (float) $paymentA->amount, $this->actor, 'alloc-a');

        $order->refresh();
        $this->assertSame(ServiceOrderPaymentStatus::Partial, $order->payment_status);
        $this->assertGreaterThan(0, $this->payments->outstandingForInvoice($invoice));

        $remaining = $this->payments->outstandingForInvoice($invoice);
        $paymentB = $this->payments->recordPayment(
            $this->customer,
            $remaining,
            'Cash',
            now(),
            $this->actor,
            idempotencyKey: 'pay-b',
        );
        $this->payments->allocatePayment($paymentB, $invoice, $remaining, $this->actor, 'alloc-b');

        $order->refresh();
        $this->assertSame(ServiceOrderPaymentStatus::Paid, $order->payment_status);
        $this->assertSame(0.0, $this->payments->outstandingForInvoice($invoice));
    }

    public function test_over_allocation_is_rejected(): void
    {
        $invoice = $this->issuedInvoice();
        $payment = $this->payments->recordPayment($this->customer, 1000, 'Cash', now(), $this->actor);

        $this->expectException(ValidationException::class);
        $this->payments->allocatePayment(
            $payment,
            $invoice,
            (float) $invoice->invoice_value + 1,
            $this->actor,
        );
    }

    public function test_negative_allocation_is_rejected(): void
    {
        $invoice = $this->issuedInvoice();
        $payment = $this->payments->recordPayment($this->customer, 1000, 'Cash', now(), $this->actor);

        $this->expectException(ValidationException::class);
        $this->payments->allocatePayment($payment, $invoice, -10, $this->actor);
    }

    public function test_cancelled_invoice_cannot_receive_payment(): void
    {
        $invoice = $this->issuedInvoice();
        $this->invoices->cancel($invoice, $this->actor, 'Test cancellation');
        $payment = $this->payments->recordPayment($this->customer, 1000, 'Cash', now(), $this->actor);

        $this->expectException(ValidationException::class);
        $this->payments->allocatePayment($payment, $invoice->fresh(), 100, $this->actor);
    }

    public function test_duplicate_payment_and_allocation_do_not_double_count(): void
    {
        $invoice = $this->issuedInvoice();
        $payment = $this->payments->recordPayment(
            $this->customer,
            1000,
            'Cash',
            now(),
            $this->actor,
            idempotencyKey: 'dup-pay',
        );
        $duplicatePayment = $this->payments->recordPayment(
            $this->customer,
            1000,
            'Cash',
            now(),
            $this->actor,
            idempotencyKey: 'dup-pay',
        );
        $this->assertSame($payment->id, $duplicatePayment->id);

        $allocation = $this->payments->allocatePayment($payment, $invoice, 500, $this->actor, 'dup-alloc');
        $duplicateAllocation = $this->payments->allocatePayment($payment, $invoice, 500, $this->actor, 'dup-alloc');
        $this->assertSame($allocation->id, $duplicateAllocation->id);
        $this->assertSame(500.0, (float) PaymentAllocation::query()->where('statutory_invoice_id', $invoice->id)->sum('amount'));
    }

    public function test_complete_service_scenario_produces_zero_inventory_mutations(): void
    {
        $before = $this->inventoryTableCounts();

        $quote = $this->createThreeLineQuote();
        $order = $this->quotes->convertToOrder($quote, $this->actor);
        $invoice = $this->invoices->issueFromServiceOrder($order, $this->actor);

        $partial = round((float) $invoice->invoice_value * 0.25, 2);
        $paymentA = $this->payments->recordPayment($this->customer, $partial, 'UPI', now(), $this->actor);
        $this->payments->allocatePayment($paymentA, $invoice, $partial, $this->actor);

        $remaining = $this->payments->outstandingForInvoice($invoice);
        $paymentB = $this->payments->recordPayment($this->customer, $remaining, 'Cash', now(), $this->actor);
        $this->payments->allocatePayment($paymentB, $invoice, $remaining, $this->actor);

        $after = $this->inventoryTableCounts();

        foreach ($before as $table => $count) {
            $this->assertSame($count, $after[$table], "Inventory table {$table} changed during service flow.");
        }

        $this->assertSame(ServiceOrderPaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(0.0, $this->payments->outstandingForInvoice($invoice->fresh()));
    }

    /**
     * @return array<string, int>
     */
    private function inventoryTableCounts(): array
    {
        return [
            'inventory_products' => InventoryProduct::query()->count(),
            'inventory_sales' => InventorySale::query()->count(),
            'inventory_sale_lines' => InventorySaleLine::query()->count(),
            'inventory_sale_serials' => InventorySaleSerial::query()->count(),
            'inventory_stock_balances' => InventoryStockBalance::query()->count(),
            'inventory_movements' => InventoryMovement::query()->count(),
            'inventory_serials' => InventorySerial::query()->count(),
            'inventory_reservations' => InventoryReservation::query()->count(),
        ];
    }

    private function createThreeLineQuote(): ServiceQuote
    {
        return $this->quotes->createQuote(
            $this->customer,
            $this->branch,
            [
                ['service_item_id' => $this->rdItem->id, 'qty' => 1],
                ['service_item_id' => $this->amcItem->id, 'qty' => 1],
                [
                    'service_item_id' => $this->customItem->id,
                    'qty' => 1,
                    'unit_price_ex_gst' => 1000.00,
                ],
            ],
            $this->actor,
            billingState: 'Karnataka',
            placeOfSupplyState: 'Karnataka',
        );
    }

    private function serviceOrderFromQuote(): ServiceOrder
    {
        $quote = $this->createThreeLineQuote();

        return $this->quotes->convertToOrder($quote, $this->actor);
    }

    private function issuedInvoice(): StatutoryInvoice
    {
        return $this->invoices->issueFromServiceOrder($this->serviceOrderFromQuote(), $this->actor);
    }
}
