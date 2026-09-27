<?php

namespace Tests\Feature\Refunds;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\ApprovedRefundMethod;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\RefundStatus;
use App\Enums\RefundStatutoryAdjustmentStatus;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Events\Finance\RefundCompleted;
use App\Models\EInvoiceRecord;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\RefundRequest;
use App\Models\RefundStatutoryAdjustment;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceCancellation;
use App\Models\User;
use App\ReadModels\Finance\CaMonthlyStatutoryLineReadModel;
use App\Services\Outbox\OutboxProcessorService;
use App\Services\Refunds\RefundStatutoryAdjustmentOutboxWriter;
use App\Services\Refunds\RefundStatutoryAdjustmentService;
use App\Services\StatutoryInvoice\Data\EInvoiceCancelResult;
use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\Support\FakeEInvoiceGateway;
use Tests\TestCase;

class RefundStatutoryAdjustmentTest extends TestCase
{
    use CreatesStatutoryInvoicesForEinvoice;
    use RefreshDatabase;

    private User $systemUser;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->configureLocationSellerIdentity();

        config([
            'refunds.statutory_adjustment.enabled' => true,
            'cashfree.system_user_email' => 'superadmin@radium.local',
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.einvoice.irn_cancellation_window_hours' => 24,
            'statutory_invoices.einvoice.issuance_policy' => 'all_eligible_b2b',
        ]);

        $this->systemUser = User::factory()->create([
            'email' => 'superadmin@radium.local',
            'is_active' => true,
        ]);
        $this->systemUser->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    public function test_production_feature_flag_defaults_to_disabled(): void
    {
        $refundsConfig = require config_path('refunds.php');

        $this->assertFalse($refundsConfig['statutory_adjustment']['enabled']);
    }

    public function test_feature_flag_disabled_skips_enqueue_on_refund_completed(): void
    {
        config(['refunds.statutory_adjustment.enabled' => false]);

        [$order, $invoice, $refund] = $this->fullRefundFixture();

        RefundCompleted::dispatch($refund, $this->systemUser);

        $this->assertDatabaseCount('refund_statutory_adjustments', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_partial_refund_records_not_applicable_without_enqueue(): void
    {
        [$order, $invoice, $refund] = $this->fullRefundFixture(paymentAmount: 1000, refundAmount: 400);

        $this->enqueue($refund);

        $adjustment = RefundStatutoryAdjustment::query()->firstOrFail();
        $this->assertSame(RefundStatutoryAdjustmentStatus::NotApplicable, $adjustment->status);
        $this->assertSame('partial_refund', $adjustment->skip_reason);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_cumulative_partial_refunds_trigger_only_when_full_amount_reached(): void
    {
        [$order, $invoice] = $this->orderWithInvoice(paymentAmount: 1000);

        $first = $this->completedRefund($order, amount: 400);
        $this->enqueue($first);
        $this->assertSame('partial_refund', RefundStatutoryAdjustment::query()->where('refund_request_id', $first->id)->value('skip_reason'));

        $second = $this->completedRefund($order, amount: 600);
        $this->enqueue($second);
        $adjustment = RefundStatutoryAdjustment::query()->where('refund_request_id', $second->id)->firstOrFail();
        $this->assertSame(RefundStatutoryAdjustmentStatus::Pending, $adjustment->status);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => RefundStatutoryAdjustmentOutboxWriter::EVENT_TYPE,
            'aggregate_id' => $second->id,
        ]);
    }

    public function test_b2b_within_window_cancels_irn_and_invoice_after_full_refund(): void
    {
        $fake = FakeEInvoiceGateway::succeeding();
        $this->app->instance(EInvoiceGateway::class, $fake);

        [$order, $invoice, $refund] = $this->fullRefundFixture();
        $this->attachSubmittedIrn($invoice, hoursAgo: 2);

        $this->enqueueAndProcess($refund);

        $adjustment = RefundStatutoryAdjustment::query()->where('refund_request_id', $refund->id)->firstOrFail();
        $this->assertSame(RefundStatutoryAdjustmentStatus::Succeeded, $adjustment->status);
        $this->assertSame(StatutoryInvoiceStatus::Cancelled, $invoice->fresh()->status);
        $this->assertSame('success', $adjustment->orchestrator_result['irn_action']['status'] ?? null);
        $this->assertSame('not_required', $adjustment->orchestrator_result['credit_note_action']['status'] ?? null);
        $this->assertSame(1, $fake->cancelCount);
    }

    public function test_b2b_beyond_window_issues_credit_note_and_keeps_invoice_issued(): void
    {
        [$order, $invoice, $refund] = $this->fullRefundFixture();
        $this->attachSubmittedIrn($invoice, hoursAgo: 48);

        $this->enqueueAndProcess($refund);

        $adjustment = RefundStatutoryAdjustment::query()->where('refund_request_id', $refund->id)->firstOrFail();
        $this->assertSame(RefundStatutoryAdjustmentStatus::Succeeded, $adjustment->status);
        $this->assertSame(StatutoryInvoiceStatus::Issued, $invoice->fresh()->status);
        $this->assertSame('issued', $adjustment->orchestrator_result['credit_note_action']['status'] ?? null);

        $creditNote = StatutoryInvoice::query()
            ->where('original_statutory_invoice_id', $invoice->id)
            ->where('document_type', StatutoryInvoiceDocumentType::CreditNote)
            ->first();
        $this->assertNotNull($creditNote);
        $this->assertNotNull($creditNote->billing_address_structured);
    }

    public function test_b2c_full_refund_cancels_invoice_without_credit_note(): void
    {
        [$order, $invoice, $refund] = $this->fullRefundFixture(buyerGstin: null);

        $this->enqueueAndProcess($refund);

        $adjustment = RefundStatutoryAdjustment::query()->where('refund_request_id', $refund->id)->firstOrFail();
        $this->assertSame(RefundStatutoryAdjustmentStatus::Succeeded, $adjustment->status);
        $this->assertSame(StatutoryInvoiceStatus::Cancelled, $invoice->fresh()->status);
        $this->assertSame('not_required', $adjustment->orchestrator_result['credit_note_action']['status'] ?? null);
        $this->assertSame(0, StatutoryInvoice::query()->where('document_type', StatutoryInvoiceDocumentType::CreditNote)->count());
    }

    public function test_b2b_without_submitted_irn_cancels_locally(): void
    {
        $fake = FakeEInvoiceGateway::succeeding();
        $this->app->instance(EInvoiceGateway::class, $fake);

        [$order, $invoice, $refund] = $this->fullRefundFixture();

        $this->enqueueAndProcess($refund);

        $this->assertSame(0, $fake->cancelCount);
        $this->assertSame(StatutoryInvoiceStatus::Cancelled, $invoice->fresh()->status);
    }

    public function test_already_cancelled_invoice_is_idempotent_skip(): void
    {
        [$order, $invoice, $refund] = $this->fullRefundFixture();
        $invoice->update([
            'status' => StatutoryInvoiceStatus::Cancelled,
            'cancelled_at' => now(),
            'cancel_reason' => 'Manual cancel',
        ]);

        $this->enqueue($refund);

        $adjustment = RefundStatutoryAdjustment::query()->firstOrFail();
        $this->assertSame(RefundStatutoryAdjustmentStatus::NotApplicable, $adjustment->status);
        $this->assertSame('invoice_already_cancelled', $adjustment->skip_reason);
    }

    public function test_existing_credit_note_is_idempotent_skip(): void
    {
        [$order, $invoice, $refund] = $this->fullRefundFixture();
        $this->makeTaxInvoice([
            'document_type' => StatutoryInvoiceDocumentType::CreditNote,
            'invoice_number' => 'CN-ADJ-1',
            'original_statutory_invoice_id' => $invoice->id,
            'buyer_gstin' => '07AAAAA0000A1Z5',
        ]);

        $this->enqueue($refund);

        $adjustment = RefundStatutoryAdjustment::query()->firstOrFail();
        $this->assertSame(RefundStatutoryAdjustmentStatus::NotApplicable, $adjustment->status);
        $this->assertSame('existing_credit_note', $adjustment->skip_reason);
    }

    public function test_duplicate_refund_completed_events_enqueue_one_adjustment(): void
    {
        [$order, $invoice, $refund] = $this->fullRefundFixture();

        RefundCompleted::dispatch($refund, $this->systemUser);
        RefundCompleted::dispatch($refund->fresh(), $this->systemUser);

        $this->assertSame(1, RefundStatutoryAdjustment::query()->count());
        $this->assertSame(1, OutboxEvent::query()->where('event_type', RefundStatutoryAdjustmentOutboxWriter::EVENT_TYPE)->count());
    }

    public function test_duplicate_outbox_processing_is_idempotent(): void
    {
        $fake = FakeEInvoiceGateway::succeeding();
        $this->app->instance(EInvoiceGateway::class, $fake);

        [$order, $invoice, $refund] = $this->fullRefundFixture();
        $this->attachSubmittedIrn($invoice, hoursAgo: 2);

        $this->enqueue($refund);
        $adjustment = RefundStatutoryAdjustment::query()->firstOrFail();
        $service = app(RefundStatutoryAdjustmentService::class);
        $service->processOutboxEvent($adjustment->id);
        $service->processOutboxEvent($adjustment->id);

        $this->assertSame(1, StatutoryInvoiceCancellation::query()->count());
        $this->assertSame(1, $fake->cancelCount);
    }

    public function test_provider_failure_leaves_refund_completed_and_marks_adjustment_retryable(): void
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $fake->queueCancel(EInvoiceCancelResult::permanentFailure('fake', ['reason' => 'simulated']));
        $this->app->instance(EInvoiceGateway::class, $fake);

        [$order, $invoice, $refund] = $this->fullRefundFixture();
        $this->attachSubmittedIrn($invoice, hoursAgo: 1);

        $this->enqueue($refund);
        $adjustment = RefundStatutoryAdjustment::query()->firstOrFail();
        $service = app(RefundStatutoryAdjustmentService::class);

        try {
            $service->processOutboxEvent($adjustment->id);
        } catch (\Throwable) {
            // Expected retryable statutory failure.
        }

        $this->assertSame(RefundStatus::Completed, $refund->fresh()->status);
        $this->assertSame(RefundStatutoryAdjustmentStatus::FailedRetryable, $adjustment->fresh()->status);
        $this->assertSame(StatutoryInvoiceStatus::Issued, $invoice->fresh()->status);

        $fake->withCancelDefault(EInvoiceCancelResult::success('fake', str_repeat('b', 64)));
        $service->processOutboxEvent($adjustment->id);

        $this->assertSame(RefundStatutoryAdjustmentStatus::Succeeded, $adjustment->fresh()->status);
    }

    public function test_refund_destination_does_not_change_statutory_treatment(): void
    {
        $fake = FakeEInvoiceGateway::succeeding();
        $this->app->instance(EInvoiceGateway::class, $fake);

        [$order, $invoice, $walletRefund] = $this->fullRefundFixture(approvedMethod: ApprovedRefundMethod::Wallet->value);
        $this->attachSubmittedIrn($invoice, hoursAgo: 2);
        $this->enqueueAndProcess($walletRefund);

        [$order2, $invoice2, $cashfreeRefund] = $this->fullRefundFixture(
            orderId: 'RD-ADJ-9002',
            approvedMethod: ApprovedRefundMethod::Cashfree->value,
        );
        $this->attachSubmittedIrn($invoice2, hoursAgo: 2, irn: str_repeat('c', 64));
        $this->enqueueAndProcess($cashfreeRefund);

        $this->assertSame(StatutoryInvoiceStatus::Cancelled, $invoice->fresh()->status);
        $this->assertSame(StatutoryInvoiceStatus::Cancelled, $invoice2->fresh()->status);
    }

    public function test_generated_credit_note_follows_ca_monthly_negative_offset_treatment(): void
    {
        [$order, $invoice, $refund] = $this->fullRefundFixture();
        $this->attachSubmittedIrn($invoice, hoursAgo: 72);

        $this->enqueueAndProcess($refund);

        $creditNote = StatutoryInvoice::query()
            ->where('original_statutory_invoice_id', $invoice->id)
            ->where('document_type', StatutoryInvoiceDocumentType::CreditNote)
            ->firstOrFail();

        $readModel = app(CaMonthlyStatutoryLineReadModel::class);
        $request = Request::create('/', 'GET', [
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-30',
        ]);
        $preflight = $readModel->preflight($request);
        $rows = $readModel->exportRows($request);

        $this->assertSame(1, $preflight->creditNoteCount);
        $this->assertSame('0.00', $preflight->taxableAmountTotal);
        $this->assertSame('0.00', $preflight->totalAmountTotal);
        $this->assertCount(2, $rows);
        $this->assertSame($creditNote->invoice_number, $rows[1][2]);
    }

    public function test_refund_show_displays_statutory_adjustment_status(): void
    {
        [$order, $invoice, $refund] = $this->fullRefundFixture();
        $this->enqueue($refund);

        $this->actingAs($this->systemUser)
            ->get(route('refunds.show', $refund))
            ->assertOk()
            ->assertSee('Statutory Adjustment')
            ->assertSee('pending');
    }

    /**
     * @return array{0: Order, 1: StatutoryInvoice, 2: RefundRequest}
     */
    private function fullRefundFixture(
        string $orderId = 'RD-ADJ-9001',
        float $paymentAmount = 118.00,
        float $refundAmount = 118.00,
        ?string $buyerGstin = '07AAAAA0000A1Z5',
        string $approvedMethod = 'wallet', // ApprovedRefundMethod value
    ): array {
        [$order, $invoice] = $this->orderWithInvoice($paymentAmount, $orderId, $buyerGstin);
        $refund = $this->completedRefund($order, $refundAmount, $approvedMethod);

        return [$order, $invoice, $refund];
    }

    /**
     * @return array{0: Order, 1: StatutoryInvoice}
     */
    private function orderWithInvoice(
        float $paymentAmount,
        string $orderId = 'RD-ADJ-9001',
        ?string $buyerGstin = '07AAAAA0000A1Z5',
    ): array {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => 'active',
            'payment_amount' => $paymentAmount,
            'created_by' => $this->systemUser->id,
        ]);

        $invoice = $this->makeTaxInvoice([
            'support_order_id' => $order->id,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => $orderId,
            'buyer_gstin' => $buyerGstin,
            'billing_address_structured' => [
                'line1' => '1 Test Street',
                'city' => 'New Delhi',
                'state' => 'Delhi',
                'pincode' => '110001',
            ],
        ]);

        return [$order, $invoice];
    }

    private function completedRefund(Order $order, float $amount, string $approvedMethod = 'wallet'): RefundRequest
    {
        return RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-ADJ-'.uniqid(),
            'amount' => $amount,
            'refund_amount' => $amount,
            'reason' => 'Customer refund after service cancellation.',
            'status' => RefundStatus::Completed,
            'total_paid_amount' => $order->payment_amount,
            'already_refunded_amount' => $amount,
            'maximum_refundable' => max(0, (float) $order->payment_amount - $amount),
            'approved_refund_method' => $approvedMethod,
            'requested_by' => $this->systemUser->id,
            'executed_by' => $this->systemUser->id,
            'executed_at' => now(),
            'closed_at' => now(),
        ]);
    }

    private function enqueue(RefundRequest $refund): void
    {
        app(RefundStatutoryAdjustmentService::class)->enqueueFromRefundCompleted($refund->fresh(['order']));
    }

    private function enqueueAndProcess(RefundRequest $refund): void
    {
        $this->enqueue($refund);
        app(OutboxProcessorService::class)->process(1);
    }

    private function attachSubmittedIrn(StatutoryInvoice $invoice, int $hoursAgo, ?string $irn = null): void
    {
        EInvoiceRecord::query()->updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'provider' => 'fake',
                'irn' => $irn ?? str_repeat('a', 64),
                'ack_no' => 'ACK-ADJ',
                'ack_date' => Carbon::now()->subHours($hoursAgo),
                'status' => EInvoiceRecordStatus::Submitted->value,
            ],
        );
    }
}
