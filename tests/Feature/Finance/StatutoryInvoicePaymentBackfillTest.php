<?php

namespace Tests\Feature\Finance;

use App\Enums\CustomerPaymentSource;
use App\Enums\PosHistoricalPaymentMethod;
use App\Enums\StatutoryInvoicePaymentBackfillOutcome;
use App\Enums\StatutoryInvoicePaymentReconciliationStatus;
use App\Enums\StatutoryInvoicePaymentStatus;
use App\Models\AuditLog;
use App\Models\CustomerPayment;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\InventoryProduct;
use App\Models\PaymentAllocation;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoicePaymentReconciliation;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\ServicePos\ServicePaymentService;
use App\Services\StatutoryInvoice\StatutoryInvoiceCancellationOrchestrator;
use App\Services\StatutoryInvoice\StatutoryInvoicePaymentBackfillService;
use App\Services\StatutoryInvoice\StatutoryInvoicePaymentReadService;
use App\Services\StatutoryInvoice\StatutoryInvoicePaymentReconciliationService;
use App\Services\StatutoryInvoice\StatutoryInvoiceRefundReviewService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StatutoryInvoicePaymentBackfillTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $financeViewer;

    private InventoryBranch $branch;

    private PosSaleService $sales;

    private StatutoryInvoiceService $invoices;

    private ServicePaymentService $payments;

    private StatutoryInvoicePaymentBackfillService $backfill;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->configureLocationSellerIdentity();

        config([
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
        ]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->financeViewer = User::factory()->create(['is_active' => true]);
        $this->financeViewer->givePermissionTo([
            RolePermissionSeeder::PERMISSION_FINANCE_VIEW,
            RolePermissionSeeder::PERMISSION_FINANCE_INVOICES_VIEW,
            RolePermissionSeeder::PERMISSION_FINANCE_PAYMENTS_RECORD,
        ]);

        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);

        $this->sales = app(PosSaleService::class);
        $this->invoices = app(StatutoryInvoiceService::class);
        $this->payments = app(ServicePaymentService::class);
        $this->backfill = app(StatutoryInvoicePaymentBackfillService::class);
    }

    public function test_historical_pos_invoice_without_allocation_is_unpaid_and_requires_reconciliation(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Cash', 'HIST-REQ-1');
        $summary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice);

        $this->assertSame(StatutoryInvoicePaymentStatus::Unpaid, $summary->status);
        $this->assertSame(StatutoryInvoicePaymentReconciliationStatus::Required, $summary->reconciliationStatus);
        $this->assertTrue($summary->reconciliationRequired);
    }

    public function test_historical_pos_invoice_with_existing_allocation_derives_completed_reconciliation(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Cash', 'HIST-ALLOC-1');
        $customer = $invoice->inventorySale?->customer;
        $this->assertNotNull($customer);

        $this->recordNormalPayment($customer, $invoice, 118.00, 'Cash', reference: 'NORMAL-ALLOC-1');

        $summary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice->fresh());
        $this->assertSame(StatutoryInvoicePaymentStatus::Paid, $summary->status);
        $this->assertSame(StatutoryInvoicePaymentReconciliationStatus::Required, $summary->reconciliationStatus);
        $this->assertTrue($summary->reconciliationRequired);
    }

    public function test_admin_can_access_backfill_route_with_permission(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Bank Transfer', 'HIST-AUTH-1');

        $this->actingAs($this->admin)
            ->post(route('finance.invoices.payment-backfill', $invoice), $this->unpaidPayload())
            ->assertRedirect(route('finance.invoices.show', $invoice));
    }

    public function test_unauthorized_user_cannot_access_backfill_route(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Cash', 'HIST-DENY-1');

        $this->actingAs($this->financeViewer)
            ->post(route('finance.invoices.payment-backfill', $invoice), $this->unpaidPayload())
            ->assertForbidden();
    }

    public function test_backfill_unpaid_creates_no_payment_or_allocation(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Cash', 'HIST-UNPAID-1');

        $record = $this->backfill->backfill($invoice, $this->admin, $this->unpaidPayload());

        $this->assertSame(StatutoryInvoicePaymentBackfillOutcome::Unpaid, $record->outcome);
        $this->assertSame(0, CustomerPayment::query()->count());
        $this->assertSame(0, PaymentAllocation::query()->count());

        $summary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice->fresh());
        $this->assertSame(StatutoryInvoicePaymentStatus::Unpaid, $summary->status);
        $this->assertSame(StatutoryInvoicePaymentReconciliationStatus::Completed, $summary->reconciliationStatus);
    }

    public function test_backfill_partially_paid_creates_payment_and_allocation(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Bank Transfer', 'HIST-PART-1');

        $record = $this->backfill->backfill($invoice, $this->admin, $this->verifiedPaymentPayload(
            amount: 50.00,
            method: PosHistoricalPaymentMethod::Cash,
            reference: null,
        ));

        $this->assertSame(StatutoryInvoicePaymentBackfillOutcome::PartiallyPaid, $record->outcome);
        $this->assertNotNull($record->locked_at);
        $this->assertSame(1, CustomerPayment::query()->count());
        $this->assertSame(1, PaymentAllocation::query()->count());

        $summary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice->fresh());
        $this->assertSame(StatutoryInvoicePaymentStatus::PartiallyPaid, $summary->status);
        $this->assertSame(50.00, $summary->amountReceived);
        $this->assertSame(68.00, $summary->amountOutstanding);
        $this->assertTrue($summary->reconciliationRequired);
    }

    public function test_backfill_paid_creates_full_payment_and_allocation(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('UPI', 'HIST-PAID-1');

        $record = $this->backfill->backfill($invoice, $this->admin, $this->verifiedPaymentPayload(
            amount: 118.00,
            method: PosHistoricalPaymentMethod::HdfcD,
            reference: 'UTR-HDFC-D-001',
        ));

        $this->assertSame(StatutoryInvoicePaymentBackfillOutcome::Paid, $record->outcome);
        $this->assertNotNull($record->locked_at);
        $summary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice->fresh());
        $this->assertSame(StatutoryInvoicePaymentStatus::Paid, $summary->status);
        $this->assertSame(118.00, $summary->amountReceived);
        $this->assertFalse($summary->reconciliationRequired);
    }

    public function test_historical_backfill_method_list_contains_only_allowed_methods(): void
    {
        $labels = array_map(
            fn (PosHistoricalPaymentMethod $method): string => $method->label(),
            PosHistoricalPaymentMethod::backfillCases(),
        );

        $this->assertSame(['HDFC D', 'HDFC M', 'INDUS', 'CASH'], $labels);
    }

    #[DataProvider('supportedHistoricalPaymentMethodProvider')]
    public function test_supported_historical_payment_methods_are_accepted(
        PosHistoricalPaymentMethod $method,
        ?string $reference,
        ?string $bankName,
    ): void {
        $invoice = $this->issueHistoricalPosInvoice('Bank Transfer', 'METHOD-'.$method->value);

        $record = $this->backfill->backfill($invoice, $this->admin, $this->verifiedPaymentPayload(
            amount: 118.00,
            method: $method,
            reference: $reference,
            bankName: $bankName,
        ));
        $this->assertSame($method->label(), $record->payment_method);
    }

    /**
     * @return array<string, array{0: PosHistoricalPaymentMethod, 1: ?string, 2: ?string}>
     */
    public static function supportedHistoricalPaymentMethodProvider(): array
    {
        return [
            'HDFC D' => [PosHistoricalPaymentMethod::HdfcD, 'UTR-HDFC-D-001', null],
            'HDFC M' => [PosHistoricalPaymentMethod::HdfcM, 'UTR-HDFC-M-001', null],
            'INDUS' => [PosHistoricalPaymentMethod::Indus, 'UTR-INDUS-001', null],
            'CASH' => [PosHistoricalPaymentMethod::Cash, null, null],
        ];
    }

    #[DataProvider('unsupportedHistoricalPaymentMethodProvider')]
    public function test_unsupported_historical_payment_methods_are_rejected_server_side(
        PosHistoricalPaymentMethod $method,
    ): void {
        $invoice = $this->issueHistoricalPosInvoice('Bank Transfer', 'REJECT-'.$method->value);

        $this->expectException(ValidationException::class);
        $this->backfill->backfill($invoice, $this->admin, $this->verifiedPaymentPayload(
            amount: 118.00,
            method: $method,
            reference: 'REF-'.$method->value,
        ));
    }

    /**
     * @return array<string, array{0: PosHistoricalPaymentMethod}>
     */
    public static function unsupportedHistoricalPaymentMethodProvider(): array
    {
        return [
            'UPI - HDFC' => [PosHistoricalPaymentMethod::UpiHdfc],
            'UPI - INDUS' => [PosHistoricalPaymentMethod::UpiIndus],
            'CARD' => [PosHistoricalPaymentMethod::Card],
            'OTHER BANK' => [PosHistoricalPaymentMethod::OtherBank],
            'OTHER UPI' => [PosHistoricalPaymentMethod::OtherUpi],
        ];
    }

    public function test_general_finance_payment_method_enum_remains_unrestricted(): void
    {
        $labels = PosHistoricalPaymentMethod::labels();

        $this->assertContains('UPI - HDFC', $labels);
        $this->assertContains('CARD', $labels);
        $this->assertContains('OTHER BANK', $labels);
        $this->assertContains('OTHER UPI', $labels);
        $this->assertCount(count(PosHistoricalPaymentMethod::cases()), $labels);
    }

    public function test_payment_date_is_required_for_paid_backfill(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Cash', 'NO-DATE-1');

        $payload = $this->verifiedPaymentPayload(
            amount: 118.00,
            method: PosHistoricalPaymentMethod::Cash,
        );
        unset($payload['payment_date']);

        $this->expectException(ValidationException::class);
        $this->backfill->backfill($invoice, $this->admin, $payload);
    }

    public function test_non_cash_installment_without_reference_is_rejected(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Bank Transfer', 'NO-REF-1');

        $this->expectException(ValidationException::class);
        $this->backfill->backfill($invoice, $this->admin, $this->verifiedPaymentPayload(
            amount: 118.00,
            method: PosHistoricalPaymentMethod::HdfcD,
            reference: null,
        ));
    }

    public function test_amount_validation_rejects_over_allocation(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Cash', 'OVER-1');

        $this->expectException(ValidationException::class);
        $this->backfill->backfill($invoice, $this->admin, $this->verifiedPaymentPayload(
            amount: 999.00,
            method: PosHistoricalPaymentMethod::Cash,
        ));
    }

    public function test_verified_payment_after_unpaid_supersedes_unpaid_decision(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Cash', 'UNPAID-CORRECT-1');
        $this->backfill->backfill($invoice, $this->admin, $this->unpaidPayload());

        $record = $this->backfill->backfill($invoice->fresh(), $this->admin, $this->verifiedPaymentPayload(
            amount: 118.00,
            method: PosHistoricalPaymentMethod::Cash,
        ));

        $this->assertSame(StatutoryInvoicePaymentBackfillOutcome::Paid, $record->outcome);
        $this->assertSame(1, CustomerPayment::query()->count());
        $this->assertSame(1, PaymentAllocation::query()->count());
        $summary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice->fresh());
        $this->assertSame(StatutoryInvoicePaymentStatus::Paid, $summary->status);
    }

    public function test_duplicate_unpaid_backfill_is_idempotent(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Cash', 'DUP-UNPAID-1');
        $first = $this->backfill->backfill($invoice, $this->admin, $this->unpaidPayload());
        $second = $this->backfill->backfill($invoice->fresh(), $this->admin, $this->unpaidPayload());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(StatutoryInvoicePaymentBackfillOutcome::Unpaid, $second->outcome);
        $this->assertSame(1, StatutoryInvoicePaymentReconciliation::query()->count());
        $this->assertSame(0, CustomerPayment::query()->count());
    }

    public function test_idempotent_backfill_returns_existing_record(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Cash', 'IDEM-1');
        $first = $this->backfill->backfill($invoice, $this->admin, $this->unpaidPayload());
        $second = $this->backfill->backfill($invoice->fresh(), $this->admin, $this->unpaidPayload());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, StatutoryInvoicePaymentReconciliation::query()->count());
    }

    public function test_backfill_writes_audit_event(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Cash', 'AUDIT-1');
        $this->backfill->backfill($invoice, $this->admin, $this->unpaidPayload());

        $this->assertSame(
            1,
            AuditLog::query()->where('event', StatutoryInvoicePaymentBackfillService::EVENT_COMPLETED)->count(),
        );
    }

    public function test_historical_backfill_source_is_recorded_on_payment(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('UPI', 'SOURCE-1');
        $this->backfill->backfill($invoice, $this->admin, $this->verifiedPaymentPayload(
            amount: 118.00,
            method: PosHistoricalPaymentMethod::HdfcD,
            reference: 'SRC-HDFC-001',
        ));

        $payment = CustomerPayment::query()->first();
        $this->assertNotNull($payment);
        $this->assertSame(CustomerPaymentSource::HistoricalPosBackfill, $payment->source);
        $this->assertSame(
            StatutoryInvoicePaymentReconciliation::SOURCE_HISTORICAL_POS_BACKFILL,
            StatutoryInvoicePaymentReconciliation::query()->value('source'),
        );
    }

    public function test_unpaid_reconciliation_decision_is_audited(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Cash', 'AUDIT-UNPAID-1');
        $this->backfill->backfill($invoice, $this->admin, $this->unpaidPayload([
            'verification_remark' => 'No bank evidence found.',
        ]));

        $audit = AuditLog::query()->where('event', StatutoryInvoicePaymentBackfillService::EVENT_COMPLETED)->first();
        $this->assertNotNull($audit);
        $this->assertSame('unpaid', data_get($audit->new_values, 'outcome'));
        $this->assertSame('No bank evidence found.', data_get($audit->new_values, 'verification_remark'));
    }

    public function test_backfilled_payment_is_visible_to_cancellation_refund_review_without_auto_refund(): void
    {
        Http::fake();
        $invoice = $this->issueHistoricalPosInvoice('Cash', 'REFUND-READ-1');
        $this->backfill->backfill($invoice, $this->admin, $this->verifiedPaymentPayload(
            amount: 118.00,
            method: PosHistoricalPaymentMethod::Cash,
        ));

        app(StatutoryInvoiceCancellationOrchestrator::class)->cancel(
            invoice: $invoice,
            actor: $this->admin,
            reason: 'Refund review regression',
            idempotencyKey: StatutoryInvoiceCancellationOrchestrator::DEFAULT_IDEMPOTENCY_PREFIX.$invoice->id,
        );

        $review = app(StatutoryInvoiceRefundReviewService::class)->snapshot($invoice->fresh());
        $this->assertStringContainsString('Recorded customer payment', $review->message);
        $this->assertStringContainsString('118.00', $review->message);
        Http::assertNothingSent();
    }

    public function test_normal_payment_recording_is_blocked_until_reconciliation(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Cash', 'BLOCK-NORMAL-1');
        $customer = $invoice->inventorySale?->customer;
        $this->assertNotNull($customer);

        $this->expectException(ValidationException::class);
        $this->payments->assertNormalPaymentRecordingAllowed($invoice);
    }

    public function test_unpaid_backfill_locks_normal_payment_recording(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Cash', 'LOCK-UNPAID-1');

        $this->backfill->backfill($invoice, $this->admin, $this->unpaidPayload());

        $this->expectException(ValidationException::class);
        $this->payments->assertNormalPaymentRecordingAllowed($invoice->fresh());
    }

    public function test_future_invoice_can_be_created_unpaid(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Cash', 'FUTURE-UNPAID-1');
        $summary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice);

        $this->assertSame(StatutoryInvoicePaymentStatus::Unpaid, $summary->status);
        $this->assertSame(0.0, $summary->amountReceived);
    }

    public function test_future_invoice_can_be_created_with_verified_payment(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Cash', 'FUTURE-PAID-1');
        $customer = $invoice->inventorySale?->customer;
        $this->assertNotNull($customer);

        $this->backfill->backfill($invoice, $this->admin, $this->verifiedPaymentPayload(
            amount: 118.00,
            method: PosHistoricalPaymentMethod::Cash,
        ));

        $summary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice->fresh());
        $this->assertSame(StatutoryInvoicePaymentStatus::Paid, $summary->status);
    }

    public function test_future_payment_can_be_recorded_after_partial_backfill(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Cash', 'FUTURE-PART-1');
        $customer = $invoice->inventorySale?->customer;
        $this->assertNotNull($customer);

        $this->backfill->backfill($invoice, $this->admin, $this->verifiedPaymentPayload(
            amount: 50.00,
            method: PosHistoricalPaymentMethod::Cash,
            reference: null,
        ));

        $this->backfill->backfill($invoice->fresh(), $this->admin, $this->verifiedPaymentPayload(
            amount: 68.00,
            method: PosHistoricalPaymentMethod::Cash,
            reference: null,
            paymentDate: '2026-09-06',
        ));

        $summary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice->fresh());
        $this->assertSame(StatutoryInvoicePaymentStatus::Paid, $summary->status);
    }

    public function test_invoice_show_exposes_reconciliation_required_and_backfill_action(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Bank Transfer', 'UI-BACKFILL-1');

        $this->actingAs($this->admin)
            ->get(route('finance.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Payment reconciliation required', false)
            ->assertSee('Backfill payment', false);
    }

    public function test_invoice_show_keeps_backfill_open_after_partial_payment(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Cash', 'UI-PART-1');
        $this->backfill->backfill($invoice, $this->admin, $this->verifiedPaymentPayload(
            amount: 50.00,
            method: PosHistoricalPaymentMethod::Cash,
        ));

        $this->actingAs($this->admin)
            ->get(route('finance.invoices.show', $invoice->fresh()))
            ->assertOk()
            ->assertSee('Backfill payment', false);
    }

    public function test_invoice_show_hides_backfill_after_full_payment_completion(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Cash', 'UI-DONE-1');
        $this->backfill->backfill($invoice, $this->admin, $this->verifiedPaymentPayload(
            amount: 118.00,
            method: PosHistoricalPaymentMethod::Cash,
        ));

        $this->actingAs($this->admin)
            ->get(route('finance.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Payment reconciliation completed', false)
            ->assertDontSee('Backfill payment', false);
    }

    public function test_invoice_show_keeps_backfill_after_unpaid_decision_for_correction(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Cash', 'UI-UNPAID-1');
        $this->backfill->backfill($invoice, $this->admin, $this->unpaidPayload());

        $this->actingAs($this->admin)
            ->get(route('finance.invoices.show', $invoice->fresh()))
            ->assertOk()
            ->assertSee('Backfill payment', false);
    }

    public function test_pre_september_pos_invoice_does_not_require_reconciliation(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Cash', 'PRE-SEPT-1');
        DB::table('statutory_invoices')->where('id', $invoice->id)->update([
            'issued_at' => '2026-08-31 12:00:00',
        ]);

        $summary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice->fresh());
        $this->assertNull($summary->reconciliationStatus);
        $this->assertFalse($summary->reconciliationRequired);
    }

    public function test_two_partial_payments_remain_partially_paid_with_correct_outstanding(): void
    {
        $invoice = $this->setInvoiceValue($this->issueHistoricalPosInvoice('Bank Transfer', 'MULTI-2'), 100000.00);

        $this->backfill->backfill($invoice, $this->admin, $this->verifiedPaymentPayload(
            amount: 30000.00,
            method: PosHistoricalPaymentMethod::HdfcD,
            reference: 'UTR-30000-1',
            paymentDate: '2026-09-05',
        ));

        $this->backfill->backfill($invoice->fresh(), $this->admin, $this->verifiedPaymentPayload(
            amount: 20000.00,
            method: PosHistoricalPaymentMethod::HdfcM,
            reference: 'UTR-20000-2',
            paymentDate: '2026-09-06',
        ));

        $summary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice->fresh());
        $this->assertSame(StatutoryInvoicePaymentStatus::PartiallyPaid, $summary->status);
        $this->assertSame(50000.00, $summary->amountReceived);
        $this->assertSame(50000.00, $summary->amountOutstanding);
        $this->assertSame(2, CustomerPayment::query()->count());
        $this->assertSame(2, PaymentAllocation::query()->count());
        $this->assertTrue($summary->reconciliationRequired);
    }

    public function test_three_installments_complete_invoice_to_paid(): void
    {
        $invoice = $this->setInvoiceValue($this->issueHistoricalPosInvoice('Bank Transfer', 'MULTI-3'), 100000.00);

        $this->backfill->backfill($invoice, $this->admin, $this->verifiedPaymentPayload(
            amount: 30000.00,
            method: PosHistoricalPaymentMethod::HdfcD,
            reference: 'UTR-30K',
            paymentDate: '2026-09-05',
        ));
        $this->backfill->backfill($invoice->fresh(), $this->admin, $this->verifiedPaymentPayload(
            amount: 40000.00,
            method: PosHistoricalPaymentMethod::HdfcM,
            reference: 'UTR-40K',
            paymentDate: '2026-09-06',
        ));
        $record = $this->backfill->backfill($invoice->fresh(), $this->admin, $this->verifiedPaymentPayload(
            amount: 30000.00,
            method: PosHistoricalPaymentMethod::Indus,
            reference: 'UTR-30K-2',
            paymentDate: '2026-09-07',
        ));

        $summary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice->fresh());
        $this->assertSame(StatutoryInvoicePaymentBackfillOutcome::Paid, $record->outcome);
        $this->assertNotNull($record->locked_at);
        $this->assertSame(StatutoryInvoicePaymentStatus::Paid, $summary->status);
        $this->assertSame(100000.00, $summary->amountReceived);
        $this->assertSame(0.0, $summary->amountOutstanding);
        $this->assertSame(3, CustomerPayment::query()->count());
        $this->assertSame(3, PaymentAllocation::query()->count());
        $this->assertFalse($summary->reconciliationRequired);
    }

    public function test_cash_installment_works_without_reference(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Cash', 'CASH-INST-1');

        $record = $this->backfill->backfill($invoice, $this->admin, $this->verifiedPaymentPayload(
            amount: 50.00,
            method: PosHistoricalPaymentMethod::Cash,
            reference: null,
        ));

        $this->assertSame(StatutoryInvoicePaymentBackfillOutcome::PartiallyPaid, $record->outcome);
        $this->assertNull($record->reference);
    }

    public function test_duplicate_reference_is_rejected_for_second_payment(): void
    {
        $invoice = $this->setInvoiceValue($this->issueHistoricalPosInvoice('Bank Transfer', 'DUP-REF-1'), 100000.00);

        $this->backfill->backfill($invoice, $this->admin, $this->verifiedPaymentPayload(
            amount: 30000.00,
            method: PosHistoricalPaymentMethod::HdfcD,
            reference: 'SHARED-UTR-1',
        ));

        $this->expectException(ValidationException::class);
        $this->backfill->backfill($invoice->fresh(), $this->admin, $this->verifiedPaymentPayload(
            amount: 20000.00,
            method: PosHistoricalPaymentMethod::HdfcM,
            reference: 'SHARED-UTR-1',
            paymentDate: '2026-09-06',
        ));
    }

    public function test_completed_backfill_blocks_further_payment_submissions(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Cash', 'LOCK-PAID-1');
        $this->backfill->backfill($invoice, $this->admin, $this->verifiedPaymentPayload(
            amount: 118.00,
            method: PosHistoricalPaymentMethod::Cash,
        ));

        $this->expectException(ValidationException::class);
        $this->backfill->backfill($invoice->fresh(), $this->admin, $this->verifiedPaymentPayload(
            amount: 1.00,
            method: PosHistoricalPaymentMethod::Cash,
            paymentDate: '2026-09-06',
        ));
    }

    public function test_invoice_show_backfill_modal_lists_only_allowed_methods(): void
    {
        $invoice = $this->issueHistoricalPosInvoice('Bank Transfer', 'UI-METHODS-1');

        $response = $this->actingAs($this->admin)
            ->get(route('finance.invoices.show', $invoice))
            ->assertOk();

        $response->assertSee('HDFC D', false);
        $response->assertSee('HDFC M', false);
        $response->assertSee('INDUS', false);
        $response->assertSee('CASH', false);
        $response->assertDontSee('UPI - HDFC', false);
        $response->assertDontSee('OTHER BANK', false);
    }

    private function issueHistoricalPosInvoice(string $paymentMethod, string $marker): StatutoryInvoice
    {
        $invoice = $this->issuePosInvoice($paymentMethod, $marker);
        if ($invoice->issued_at === null || $invoice->issued_at->lt(app(StatutoryInvoicePaymentReconciliationService::class)->historicalPeriodStart())) {
            DB::table('statutory_invoices')->where('id', $invoice->id)->update([
                'issued_at' => '2026-09-01 10:00:00',
            ]);
        }

        return $invoice->fresh(['inventorySale.customer']) ?? $invoice;
    }

    private function issuePosInvoice(string $paymentMethod, string $marker): StatutoryInvoice
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
        app(InventoryStockService::class)->stockInSerialized($product, $this->branch, ['SN-'.$marker], $this->admin);

        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Backfill Test Customer', 'phone' => '910000'.substr(preg_replace('/\D/', '', $marker), 0, 4)],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['SN-'.$marker],
            ]],
            paymentMethod: $paymentMethod,
            actor: $this->admin,
            statutory: [
                'place_of_supply_state' => 'Delhi',
            ],
        );

        return $this->invoices->issueFromPosSale($sale, $this->admin);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function unpaidPayload(array $overrides = []): array
    {
        return array_merge([
            'outcome' => 'unpaid',
            'confirm' => '1',
            'verification_remark' => 'Verified no payment received.',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function verifiedPaymentPayload(
        float $amount,
        PosHistoricalPaymentMethod $method,
        ?string $reference = 'REF-001',
        ?string $bankName = null,
        string $paymentDate = '2026-09-05',
    ): array {
        return [
            'outcome' => 'verified_payment',
            'amount' => $amount,
            'payment_date' => $paymentDate,
            'payment_method' => $method->value,
            'bank_name' => $bankName,
            'bank_branch' => null,
            'reference' => $reference,
            'confirm' => '1',
            'verification_remark' => 'Verified historical payment.',
        ];
    }

    private function setInvoiceValue(StatutoryInvoice $invoice, float $value): StatutoryInvoice
    {
        DB::table('statutory_invoices')->where('id', $invoice->id)->update([
            'invoice_value' => $value,
        ]);

        return $invoice->fresh(['inventorySale.customer']) ?? $invoice;
    }

    private function recordNormalPayment(
        InventoryCustomer $customer,
        StatutoryInvoice $invoice,
        float $amount,
        string $method,
        ?string $reference = null,
    ): void {
        $payment = $this->payments->recordPayment(
            customer: $customer,
            amount: $amount,
            method: $method,
            paymentDate: now(),
            actor: $this->admin,
            reference: $reference,
        );

        $this->payments->allocatePayment(
            payment: $payment,
            invoice: $invoice,
            amount: $amount,
            actor: $this->admin,
        );
    }
}
