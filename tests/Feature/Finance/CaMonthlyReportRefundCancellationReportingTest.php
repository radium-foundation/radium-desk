<?php

namespace Tests\Feature\Finance;

use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatutoryAdjustmentStatus;
use App\Enums\RefundStatus;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\RefundStatutoryAdjustment;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceCancellation;
use App\Models\User;
use App\ReadModels\Finance\CaMonthlyStatutoryLineReadModel;
use App\Reports\CaMonthly\CaMonthlyReportDefinition;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\TestCase;

class CaMonthlyReportRefundCancellationReportingTest extends TestCase
{
    use CreatesStatutoryInvoicesForEinvoice;
    use RefreshDatabase;

    private const NOVEMBER_RANGE = [
        'date_from' => '2026-11-01',
        'date_to' => '2026-11-30',
    ];

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->actor = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_eligible_cancellation_on_exactly_2026_09_07_included_in_november(): void
    {
        $this->pairInNovemberWithRefundExecutedAt('2026-09-07 00:00:00', '2026-11-05 14:00:00');
        $this->assertNovemberPairNetZero(creditNoteCount: 1, rowCount: 2);
    }

    public function test_eligible_cancellation_after_2026_09_07_included_in_november(): void
    {
        $this->pairInNovemberWithRefundExecutedAt('2026-10-12 09:00:00', '2026-11-18 09:00:00');
        $this->assertNovemberPairNetZero(creditNoteCount: 1, rowCount: 2);
    }

    public function test_cancellation_before_2026_09_07_excluded_even_when_credit_note_issued_in_november(): void
    {
        [$original, $creditNote] = $this->pairInNovemberWithRefundExecutedAt('2026-09-06 23:59:00', '2026-11-10 10:00:00');
        $readModel = app(CaMonthlyStatutoryLineReadModel::class);
        $preflight = $readModel->preflight($this->novemberRequest());

        $this->assertSame(0, $preflight->creditNoteCount);
        $this->assertCount(0, $readModel->exportRows($this->novemberRequest()));
        $this->assertSame('2026-11-10 10:00:00', $creditNote->fresh()->issued_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-10 10:00:00', $original->fresh()->issued_at?->format('Y-m-d H:i:s'));
    }

    public function test_september_invoice_with_eligible_november_cancellation_included_in_november_report(): void
    {
        [$original, $creditNote] = $this->pairInNovemberWithRefundExecutedAt('2026-09-20 11:00:00', '2026-11-12 11:00:00');
        $rows = app(CaMonthlyStatutoryLineReadModel::class)->exportRows($this->novemberRequest());

        $this->assertSame('2026-09-10 10:00:00', $original->issued_at?->format('Y-m-d H:i:s'));
        $this->assertContains($original->invoice_number, array_column($rows, 2));
        $this->assertContains($creditNote->invoice_number, array_column($rows, 2));
    }

    public function test_october_invoice_with_eligible_november_cancellation_included(): void
    {
        $original = $this->makeTaxInvoice([
            'issued_at' => '2026-10-05 10:00:00',
            'invoice_number' => 'INV-OCT-PAIR',
        ]);
        $creditNote = $this->makeTaxInvoice([
            'issued_at' => '2026-11-08 10:00:00',
            'document_type' => StatutoryInvoiceDocumentType::CreditNote,
            'invoice_number' => 'CN-NOV-OCT',
            'original_statutory_invoice_id' => $original->id,
        ]);
        $this->attachRefundAdjustmentSucceeded($original, '2026-10-20 12:00:00');

        $this->assertNovemberPairNetZero(creditNoteCount: 1, rowCount: 2);
        $this->assertSame('2026-11-08 10:00:00', $creditNote->fresh()->issued_at?->format('Y-m-d H:i:s'));
    }

    public function test_credit_note_issued_in_november_retains_real_issuance_timestamp(): void
    {
        [, $creditNote] = $this->pairInNovemberWithRefundExecutedAt('2026-09-15 10:00:00', '2026-11-22 16:45:30');
        $this->assertSame('2026-11-22 16:45:30', $creditNote->fresh()->issued_at?->format('Y-m-d H:i:s'));
    }

    public function test_november_report_does_not_backdate_credit_note_issued_at(): void
    {
        [$original, $creditNote] = $this->pairInNovemberWithRefundExecutedAt('2026-09-15 10:00:00', '2026-11-03 10:00:00');
        $this->assertNotSame(
            $original->issued_at?->format('Y-m-d'),
            $creditNote->fresh()->issued_at?->format('Y-m-d'),
        );
    }

    public function test_original_and_credit_note_net_to_zero_without_double_counting(): void
    {
        $this->pairInNovemberWithRefundExecutedAt('2026-09-21 08:00:00', '2026-11-09 08:00:00');
        $this->assertNovemberPairNetZero(creditNoteCount: 1, rowCount: 2);
    }

    public function test_multiple_eligible_cancellations_represented_in_november(): void
    {
        $this->pairInNovemberWithRefundExecutedAt('2026-09-10 10:00:00', '2026-11-05 10:00:00', 'INV-A', 'CN-A');
        $this->pairInNovemberWithRefundExecutedAt('2026-09-11 10:00:00', '2026-11-06 10:00:00', 'INV-B', 'CN-B');

        $preflight = app(CaMonthlyStatutoryLineReadModel::class)->preflight($this->novemberRequest());
        $this->assertSame(2, $preflight->creditNoteCount);
        $this->assertCount(4, app(CaMonthlyStatutoryLineReadModel::class)->exportRows($this->novemberRequest()));
    }

    public function test_duplicate_report_generation_does_not_duplicate_financial_rows(): void
    {
        $this->pairInNovemberWithRefundExecutedAt('2026-09-12 10:00:00', '2026-11-07 10:00:00');
        $readModel = app(CaMonthlyStatutoryLineReadModel::class);
        $request = $this->novemberRequest();

        $rowsA = $readModel->exportRows($request);
        $rowsB = $readModel->exportRows($request);

        $this->assertSame($rowsA, $rowsB);
        $this->assertCount(2, $rowsA);
    }

    public function test_end_of_november_date_boundary_inclusive(): void
    {
        $this->pairInNovemberWithRefundExecutedAt('2026-09-12 10:00:00', '2026-11-30 23:59:00');
        $this->assertNovemberPairNetZero(creditNoteCount: 1, rowCount: 2);
    }

    public function test_credit_note_december_excluded_from_november_totals(): void
    {
        $original = $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00', 'invoice_number' => 'INV-OUT']);
        $this->makeTaxInvoice([
            'issued_at' => '2026-12-01 10:00:00',
            'document_type' => StatutoryInvoiceDocumentType::CreditNote,
            'invoice_number' => 'CN-DEC',
            'original_statutory_invoice_id' => $original->id,
        ]);
        $this->attachRefundAdjustmentSucceeded($original, '2026-09-20 10:00:00');

        $preflight = app(CaMonthlyStatutoryLineReadModel::class)->preflight($this->novemberRequest());
        $this->assertSame(0, $preflight->creditNoteCount);
    }

    public function test_missing_refund_cancellation_initiation_fails_safe_and_excludes_credit_note(): void
    {
        $original = $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);
        $this->makeTaxInvoice([
            'issued_at' => '2026-11-10 10:00:00',
            'document_type' => StatutoryInvoiceDocumentType::CreditNote,
            'original_statutory_invoice_id' => $original->id,
        ]);

        $this->assertSame(0, app(CaMonthlyStatutoryLineReadModel::class)->preflight($this->novemberRequest())->creditNoteCount);
    }

    public function test_statutory_cancellation_completed_at_used_when_no_refund_adjustment(): void
    {
        $original = $this->makeTaxInvoice(['issued_at' => '2026-08-20 10:00:00', 'invoice_number' => 'INV-DIRECT']);
        $this->makeTaxInvoice([
            'issued_at' => '2026-11-14 10:00:00',
            'document_type' => StatutoryInvoiceDocumentType::CreditNote,
            'invoice_number' => 'CN-DIRECT',
            'original_statutory_invoice_id' => $original->id,
        ]);
        StatutoryInvoiceCancellation::query()->create([
            'statutory_invoice_id' => $original->id,
            'idempotency_key' => 'direct-cancel-1',
            'actor_id' => $this->actor->id,
            'reason' => 'Order cancelled',
            'completed_at' => '2026-09-08 15:00:00',
        ]);

        $this->assertNovemberPairNetZero(creditNoteCount: 1, rowCount: 2);
    }

    public function test_unrelated_september_issued_invoice_without_pairing_unchanged_for_september_report(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00', 'invoice_number' => 'INV-PLAIN']);
        $request = Request::create('/', 'GET', ['date_from' => '2026-09-01', 'date_to' => '2026-09-30']);
        $preflight = app(CaMonthlyStatutoryLineReadModel::class)->preflight($request);

        $this->assertSame(1, $preflight->invoiceCount);
        $this->assertSame(0, $preflight->creditNoteCount);
    }

  /**
     * @return array{0: StatutoryInvoice, 1: StatutoryInvoice}
     */
    private function pairInNovemberWithRefundExecutedAt(
        string $refundExecutedAt,
        string $creditNoteIssuedAt,
        string $invoiceNumber = 'INV-SEP-PAIR',
        string $creditNoteNumber = 'CN-NOV-PAIR',
    ): array {
        $original = $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'invoice_number' => $invoiceNumber,
        ]);
        $creditNote = $this->makeTaxInvoice([
            'issued_at' => $creditNoteIssuedAt,
            'document_type' => StatutoryInvoiceDocumentType::CreditNote,
            'invoice_number' => $creditNoteNumber,
            'original_statutory_invoice_id' => $original->id,
        ]);
        $this->attachRefundAdjustmentSucceeded($original, $refundExecutedAt);

        return [$original, $creditNote];
    }

    private function attachRefundAdjustmentSucceeded(StatutoryInvoice $original, string $refundExecutedAt): void
    {
        $order = Order::query()->create([
            'order_id' => 'RD-CA-'.uniqid(),
            'serial_number' => 'SN-CA',
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => 'active',
            'payment_amount' => '118.00',
            'created_by' => $this->actor->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-CA-'.uniqid(),
            'amount' => '118.00',
            'refund_amount' => '118.00',
            'reason' => 'Cancellation reporting regression.',
            'status' => RefundStatus::Completed,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $this->actor->id,
            'executed_by' => $this->actor->id,
            'executed_at' => $refundExecutedAt,
            'closed_at' => $refundExecutedAt,
        ]);

        RefundStatutoryAdjustment::query()->create([
            'refund_request_id' => $refund->id,
            'statutory_invoice_id' => $original->id,
            'status' => RefundStatutoryAdjustmentStatus::Succeeded,
            'idempotency_key' => 'rsa-test-'.$refund->id,
            'processed_at' => $refundExecutedAt,
        ]);
    }

    private function novemberRequest(): Request
    {
        return Request::create('/', 'GET', self::NOVEMBER_RANGE);
    }

    private function assertNovemberPairNetZero(int $creditNoteCount, int $rowCount): void
    {
        $readModel = app(CaMonthlyStatutoryLineReadModel::class);
        $preflight = $readModel->preflight($this->novemberRequest());
        $rows = $readModel->exportRows($this->novemberRequest());

        $this->assertSame($creditNoteCount, $preflight->creditNoteCount);
        $this->assertSame('0.00', $preflight->taxableAmountTotal);
        $this->assertSame('0.00', $preflight->totalAmountTotal);
        $this->assertCount($rowCount, $rows);
        $this->assertCount(count(CaMonthlyReportDefinition::HEADERS), $rows[0]);
    }
}
