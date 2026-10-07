<?php

namespace Tests\Feature\Finance;

use App\Enums\ApprovedRefundMethod;
use App\Enums\CaMonthlyReportExportFormat;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\RefundStatus;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\EInvoiceRecord;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\ReadModels\Finance\CaMonthlyStatutoryLineReadModel;
use App\Reports\CaMonthly\CaMonthlyReportDefinition;
use App\Reports\CaMonthly\CaMonthlyReportInvoiceExportBuilder;
use App\Services\Finance\CaMonthlyReportExportGenerator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\TestCase;
use ZipArchive;

class CaMonthlyReportCaReadyExportTest extends TestCase
{
    use CreatesStatutoryInvoicesForEinvoice;
    use RefreshDatabase;

    private const RANGE = [
        'date_from' => '2026-09-01',
        'date_to' => '2026-09-30',
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['ca_monthly_report.sync_max_lines' => 500]);

        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    public function test_parent_headers_include_ca_ready_columns(): void
    {
        $headers = CaMonthlyReportDefinition::HEADERS;

        $this->assertNotContains('Customer Type', $headers);
        $this->assertContains('Total GST', $headers);
        $this->assertContains('Payment Status', $headers);
        $this->assertContains('Payment Method', $headers);
        $this->assertContains('Credit Note Number', $headers);
        $this->assertContains('Credit Note Status', $headers);
        $this->assertCount(31, $headers);
    }

    public function test_invoice_export_includes_b2b_customer_type_and_total_gst(): void
    {
        $invoice = $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'buyer_gstin' => '07AAAAA0000A1Z5',
        ]);

        $row = app(CaMonthlyReportInvoiceExportBuilder::class)->buildForInvoices(collect([$invoice]))[0];

        $this->assertSame('18.00', $row->parentCells[26]);
        $this->assertSame('Unpaid', $row->parentCells[27]);
        $this->assertNotContains('B2B', $row->parentCells);
        $this->assertNotContains('B2C', $row->parentCells);
    }

    public function test_line_detail_includes_unit_price_discount_and_gst_rate(): void
    {
        $invoice = $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $detail = app(CaMonthlyReportInvoiceExportBuilder::class)
            ->buildForInvoices(collect([$invoice]))[0]
            ->detailRows[0];

        $this->assertSame('100.00', $detail[3]);
        $this->assertSame('18.00%', $detail[6]);
    }

    public function test_credit_note_on_original_invoice_is_not_treated_as_refund_amount(): void
    {
        $original = $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'invoice_number' => 'INV-ORIG-1',
        ]);

        $this->makeTaxInvoice([
            'issued_at' => '2026-09-12 10:00:00',
            'document_type' => StatutoryInvoiceDocumentType::CreditNote,
            'invoice_number' => 'CN-0009',
            'original_statutory_invoice_id' => $original->id,
        ]);

        $row = app(CaMonthlyReportInvoiceExportBuilder::class)
            ->buildForInvoices(collect([$original->fresh()]))[0];

        $this->assertSame('CN-0009', $row->parentCells[28]);
        $this->assertSame('Credit Note', $row->parentCells[29]);
    }

    public function test_refund_review_flags_b2b_irn_beyond_window_without_credit_note(): void
    {
        [$order, $invoice] = $this->orderWithInvoice(
            orderId: 'RBP98',
            paymentAmount: 6747.00,
            invoiceOverrides: [
                'issued_at' => '2026-09-14 13:33:00',
                'invoice_number' => 'INV-076790',
            ],
        );
        $this->attachSubmittedIrn($invoice, ackAt: '2026-09-14 13:33:00');

        RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000313',
            'amount' => 6747.00,
            'refund_amount' => 6747.00,
            'reason' => 'Full refund.',
            'status' => RefundStatus::Closed,
            'total_paid_amount' => 6747.00,
            'already_refunded_amount' => 6747.00,
            'maximum_refundable' => 0,
            'approved_refund_method' => ApprovedRefundMethod::Cashfree,
            'requested_by' => $this->user->id,
            'executed_by' => $this->user->id,
            'executed_at' => '2026-09-18 13:27:47',
            'closed_at' => '2026-09-18 13:27:47',
        ]);

        $rows = app(CaMonthlyStatutoryLineReadModel::class)->refundReviewRows($this->request());

        $this->assertCount(1, $rows);
        $this->assertSame('REF-2026-000313', $rows[0]->cells[1]);
        $this->assertSame('INV-076790', $rows[0]->cells[3]);
        $this->assertSame('6747.00', $rows[0]->cells[6]);
        $this->assertSame('', $rows[0]->cells[13]);
        $this->assertSame(
            CaMonthlyReportDefinition::REVIEW_STATUS_POTENTIAL_CN,
            $rows[0]->cells[16],
        );
    }

    public function test_refund_review_flags_refund_before_invoice_timeline_anomaly(): void
    {
        [$order, $invoice] = $this->orderWithInvoice(
            orderId: 'RA3506948',
            paymentAmount: 1079.00,
            invoiceOverrides: [
                'issued_at' => '2026-09-19 10:11:35',
                'invoice_number' => 'INV-0767159',
            ],
        );
        $this->attachSubmittedIrn($invoice, ackAt: '2026-09-19 10:12:00');

        RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000276',
            'amount' => 1079.00,
            'refund_amount' => 1079.00,
            'reason' => 'Full refund.',
            'status' => RefundStatus::Closed,
            'total_paid_amount' => 1079.00,
            'already_refunded_amount' => 1079.00,
            'maximum_refundable' => 0,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $this->user->id,
            'executed_by' => $this->user->id,
            'executed_at' => '2026-09-08 12:26:10',
            'closed_at' => '2026-09-08 12:26:10',
        ]);

        $rows = app(CaMonthlyStatutoryLineReadModel::class)->refundReviewRows($this->request());

        $this->assertCount(1, $rows);
        $this->assertSame('REF-2026-000276', $rows[0]->cells[1]);
        $this->assertSame(
            CaMonthlyReportDefinition::REVIEW_STATUS_REFUND_BEFORE_INVOICE,
            $rows[0]->cells[16],
        );
    }

    public function test_xlsx_workbook_contains_sales_report_and_refund_review_sheets(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $path = tempnam(sys_get_temp_dir(), 'ca-ready-');
        app(CaMonthlyReportExportGenerator::class)->generateToPath(
            $this->request(),
            CaMonthlyReportExportFormat::Xlsx,
            $path,
        );

        $zip = new ZipArchive;
        $zip->open($path);
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $sheet2 = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();

        $this->assertStringContainsString('Sales Report', $workbook);
        $this->assertStringContainsString('Refund &amp; CN Review', $workbook);
        $this->assertStringContainsString('Refund &amp; CN Review', $sheet2);
        $this->assertStringContainsString('exception-only refunds', $sheet2);

        @unlink($path);
    }

    private function request(): Request
    {
        return Request::create('/finance/reports/ca-monthly', 'GET', self::RANGE);
    }

    /**
     * @return array{0: Order, 1: StatutoryInvoice}
     */
    /**
     * @param  array<string, mixed>  $invoiceOverrides
     * @return array{0: Order, 1: StatutoryInvoice}
     */
    private function orderWithInvoice(string $orderId, float $paymentAmount, array $invoiceOverrides = []): array
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => 'active',
            'payment_amount' => $paymentAmount,
            'created_by' => $this->user->id,
        ]);

        $invoice = $this->makeTaxInvoice(array_merge([
            'support_order_id' => $order->id,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => $orderId,
            'buyer_gstin' => '07AAAAA0000A1Z5',
            'issued_at' => '2026-09-10 10:00:00',
            'invoice_value' => number_format($paymentAmount, 2, '.', ''),
            'taxable_value' => number_format($paymentAmount / 1.18, 2, '.', ''),
        ], $invoiceOverrides));

        return [$order, $invoice];
    }

    private function attachSubmittedIrn(StatutoryInvoice $invoice, string $ackAt): void
    {
        EInvoiceRecord::query()->updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'provider' => 'fake',
                'irn' => str_repeat('b', 64),
                'ack_no' => 'ACK-CA',
                'ack_date' => Carbon::parse($ackAt),
                'status' => EInvoiceRecordStatus::Submitted->value,
            ],
        );
    }
}
