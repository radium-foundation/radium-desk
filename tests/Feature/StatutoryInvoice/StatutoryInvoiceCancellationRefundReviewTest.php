<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceRefundReviewStatus;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\AuditLog;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceCancellation;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\Refunds\WalletRefundExecutor;
use App\Services\StatutoryInvoice\StatutoryInvoiceCancellationOrchestrator;
use App\Services\StatutoryInvoice\StatutoryInvoiceRefundReviewService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class StatutoryInvoiceCancellationRefundReviewTest extends TestCase
{
    use RefreshDatabase;

    private PosSaleService $sales;

    private InventoryStockService $stock;

    private StatutoryInvoiceService $invoices;

    private User $admin;

    private InventoryBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        $this->sales = app(PosSaleService::class);
        $this->stock = app(InventoryStockService::class);
        $this->invoices = app(StatutoryInvoiceService::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);

        $this->configureLocationSellerIdentity();

        config([
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
        ]);
    }

    public function test_invoice_cancellation_does_not_execute_wallet_refund(): void
    {
        Http::fake();
        $walletExecutor = Mockery::mock(WalletRefundExecutor::class);
        $walletExecutor->shouldNotReceive('execute');
        $this->app->instance(WalletRefundExecutor::class, $walletExecutor);

        $order = $this->createPaidOrder('RD-REFUND-REVIEW-1', 1180.00);
        $invoice = $this->createLinkedStatutoryInvoice($order, 'INV-REFUND-REVIEW-1');

        $this->orchestrator()->cancel(
            invoice: $invoice,
            actor: $this->admin,
            reason: 'Wallet refund must not auto-run',
            idempotencyKey: $this->idempotencyKey($invoice),
        );

        Http::assertNothingSent();
        $this->assertSame(0, RefundRequest::query()->count());
    }

    public function test_invoice_cancellation_does_not_execute_opm_or_manual_refund_executor(): void
    {
        Http::fake();

        $order = $this->createPaidOrder('RD-REFUND-REVIEW-2', 500.00, paymentMethod: 'Cashfree');
        $invoice = $this->createLinkedStatutoryInvoice($order, 'INV-REFUND-REVIEW-2');

        $this->orchestrator()->cancel(
            invoice: $invoice,
            actor: $this->admin,
            reason: 'OPM refund must not auto-run',
            idempotencyKey: $this->idempotencyKey($invoice),
        );

        Http::assertNothingSent();
        $this->assertSame(0, RefundRequest::query()->count());
    }

    public function test_cancelled_invoice_with_refundable_order_is_identified_for_refund_review(): void
    {
        $order = $this->createPaidOrder('RD-REFUND-REVIEW-3', 2360.00, paymentMethod: 'Wallet');
        $invoice = $this->createLinkedStatutoryInvoice($order, 'INV-REFUND-REVIEW-3');
        $invoice->forceFill([
            'status' => StatutoryInvoiceStatus::Cancelled,
            'cancelled_by' => $this->admin->id,
            'cancelled_at' => now(),
            'cancel_reason' => 'Review bridge test',
        ])->save();

        $snapshot = app(StatutoryInvoiceRefundReviewService::class)->snapshot($invoice->fresh());

        $this->assertSame(StatutoryInvoiceRefundReviewStatus::RefundReviewRequired, $snapshot->status);
        $this->assertSame($order->id, $snapshot->linkedOrderId);
        $this->assertSame(2360.00, $snapshot->totalPaidAmount);
        $this->assertSame(2360.00, $snapshot->maximumRefundable);
    }

    public function test_repeated_cancellation_does_not_create_refund_requests_or_duplicate_refund_review_audit(): void
    {
        $order = $this->createPaidOrder('RD-REFUND-REVIEW-4', 999.00);
        $invoice = $this->createLinkedStatutoryInvoice($order, 'INV-REFUND-REVIEW-4');
        $key = $this->idempotencyKey($invoice);

        $first = $this->orchestrator()->cancel($invoice, $this->admin, 'Once only', $key);
        $second = $this->orchestrator()->cancel($first->invoice->fresh(), $this->admin, 'Once only', $key);

        $this->assertFalse($first->idempotent);
        $this->assertTrue($second->idempotent);
        $this->assertSame(0, RefundRequest::query()->count());
        $this->assertSame(1, StatutoryInvoiceCancellation::query()->count());

        $log = AuditLog::query()
            ->where('event', StatutoryInvoiceCancellationOrchestrator::EVENT_CANCELLED)
            ->where('auditable_id', $invoice->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('refund_review_required', data_get($log->new_values, 'refund_review.status'));
    }

    public function test_pos_cancellation_shows_pos_refund_boundary_without_service_refund_workflow(): void
    {
        $invoice = $this->issuePosInvoice('POS-REFUND-BOUNDARY');
        $this->orchestrator()->cancel(
            invoice: $invoice,
            actor: $this->admin,
            reason: 'POS refund boundary test',
            idempotencyKey: $this->idempotencyKey($invoice),
        );

        $snapshot = app(StatutoryInvoiceRefundReviewService::class)->snapshot($invoice->fresh());
        $this->assertSame(StatutoryInvoiceRefundReviewStatus::NotApplicable, $snapshot->status);
        $this->assertTrue($snapshot->posBoundary);

        $this->actingAs($this->admin)
            ->get(route('finance.invoices.show', $invoice->fresh(['cancellation'])))
            ->assertOk()
            ->assertSee('Refund status', false)
            ->assertSee('Not applicable', false)
            ->assertSee('manual operations', false)
            ->assertDontSee('Request refund', false);
    }

    public function test_finance_ui_shows_refund_review_required_and_request_refund_link_for_linked_order(): void
    {
        $order = $this->createPaidOrder('RD-REFUND-REVIEW-5', 1500.00);
        $invoice = $this->createLinkedStatutoryInvoice($order, 'INV-REFUND-REVIEW-5');
        $invoice->forceFill([
            'status' => StatutoryInvoiceStatus::Cancelled,
            'cancelled_by' => $this->admin->id,
            'cancelled_at' => now(),
            'cancel_reason' => 'UI review test',
        ])->save();

        $this->actingAs($this->admin)
            ->get(route('finance.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Refund review required', false)
            ->assertSee('does not refund customer money automatically', false)
            ->assertSee(route('refunds.create', ['order' => $order->id]), false);
    }

    public function test_existing_refund_request_status_is_reflected_after_cancellation(): void
    {
        $order = $this->createPaidOrder('RD-REFUND-REVIEW-6', 750.00);
        $invoice = $this->createLinkedStatutoryInvoice($order, 'INV-REFUND-REVIEW-6');
        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000777',
            'amount' => 750,
            'reason' => 'Customer return after invoice cancel review.',
            'status' => RefundStatus::PendingExecution,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $this->admin->id,
        ]);

        $invoice->forceFill([
            'status' => StatutoryInvoiceStatus::Cancelled,
            'cancelled_by' => $this->admin->id,
            'cancelled_at' => now(),
            'cancel_reason' => 'Existing refund in flight',
        ])->save();

        $snapshot = app(StatutoryInvoiceRefundReviewService::class)->snapshot($invoice->fresh());

        $this->assertSame(StatutoryInvoiceRefundReviewStatus::PendingExecution, $snapshot->status);
        $this->assertSame($refund->id, $snapshot->activeRefundRequestId);
    }

    private function orchestrator(): StatutoryInvoiceCancellationOrchestrator
    {
        return app(StatutoryInvoiceCancellationOrchestrator::class);
    }

    private function createPaidOrder(string $orderId, float $amount, ?string $paymentMethod = null): Order
    {
        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'Refund review device',
            'device_model' => 'Model R',
            'status' => 'active',
            'payment_amount' => $amount,
            'payment_method' => $paymentMethod,
            'created_by' => $this->admin->id,
        ]);
    }

    private function createLinkedStatutoryInvoice(Order $order, string $invoiceNumber): StatutoryInvoice
    {
        return StatutoryInvoice::query()->create([
            'invoice_number' => $invoiceNumber,
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => $order->order_id,
            'source_order_id' => $order->order_id,
            'idempotency_key' => 'test-'.$invoiceNumber,
            'support_order_id' => $order->id,
            'taxable_value' => $order->payment_amount,
            'tax_total' => 0,
            'invoice_value' => $order->payment_amount,
            'payment_method' => $order->payment_method,
            'issued_by' => $this->admin->id,
            'issued_at' => now(),
        ]);
    }

    private function issuePosInvoice(string $marker): StatutoryInvoice
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'MFS110-'.$marker,
            'name' => 'Mantra MFS110 '.$marker,
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $this->stock->stockInSerialized($product, $this->branch, ['POS-SERIAL-'.$marker], $this->admin);

        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'POS Refund Boundary Customer', 'phone' => '9000004321'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['POS-SERIAL-'.$marker],
            ]],
            paymentMethod: 'Cash',
            actor: $this->admin,
            statutory: [
                'place_of_supply_state' => 'Delhi',
            ],
        );

        return $this->invoices->issueFromPosSale($sale, $this->admin);
    }

    private function idempotencyKey(StatutoryInvoice $invoice): string
    {
        return StatutoryInvoiceCancellationOrchestrator::DEFAULT_IDEMPOTENCY_PREFIX.$invoice->id;
    }
}
