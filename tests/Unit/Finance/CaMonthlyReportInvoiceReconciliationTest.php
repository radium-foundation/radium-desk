<?php

namespace Tests\Unit\Finance;

use App\Reports\CaMonthly\CaMonthlyReportInvoiceExportRow;
use App\Reports\CaMonthly\CaMonthlyReportInvoiceReconciliation;
use Tests\TestCase;

class CaMonthlyReportInvoiceReconciliationTest extends TestCase
{
    public function test_reconciled_invoice_passes_identity_check(): void
    {
        $row = $this->row(
            taxableAmount: 100.0,
            cgst: 9.0,
            sgst: 9.0,
            invoiceTotal: 118.0,
            invoiceNumber: 'INV-1',
        );

        $reconciliation = new CaMonthlyReportInvoiceReconciliation;

        $this->assertTrue($reconciliation->isReconciled($row));
        $this->assertSame('0.00', $reconciliation->diagnostic($row)['delta']);
    }

    public function test_non_reconciled_invoice_reports_delta(): void
    {
        $row = $this->row(
            taxableAmount: 100.0,
            cgst: 9.0,
            sgst: 9.0,
            invoiceTotal: 120.0,
            invoiceNumber: 'INV-2',
        );

        $reconciliation = new CaMonthlyReportInvoiceReconciliation;

        $this->assertFalse($reconciliation->isReconciled($row));
        $this->assertSame('-2.00', $reconciliation->diagnostic($row)['delta']);
    }

    public function test_inv_076768_style_header_discount_reconciles(): void
    {
        $row = $this->row(
            taxableAmount: 9017.82,
            igst: 1623.21,
            headerDiscount: 0.03,
            invoiceTotal: 10641.00,
            invoiceNumber: 'INV-076768',
        );

        $reconciliation = new CaMonthlyReportInvoiceReconciliation;

        $this->assertTrue($reconciliation->isReconciled($row));
        $this->assertSame('0.00', $reconciliation->diagnostic($row)['delta']);
        $this->assertSame('10641.00', $reconciliation->diagnostic($row)['calculated_total']);
        $this->assertSame('0.03', $reconciliation->diagnostic($row)['header_discount']);
    }

    public function test_without_header_discount_inv_076768_would_not_reconcile(): void
    {
        $row = $this->row(
            taxableAmount: 9017.82,
            igst: 1623.21,
            invoiceTotal: 10641.00,
            invoiceNumber: 'INV-076768-OLD-FORMULA',
        );

        $reconciliation = new CaMonthlyReportInvoiceReconciliation;

        $this->assertFalse($reconciliation->isReconciled($row));
        $this->assertSame('0.03', $reconciliation->diagnostic($row)['delta']);
    }

    public function test_rounding_only_path_still_reconciles(): void
    {
        $row = $this->row(
            taxableAmount: 14158.50,
            igst: 2548.53,
            shortExcess: -0.03,
            invoiceTotal: 16707.00,
            invoiceNumber: 'INV-0767224',
        );

        $reconciliation = new CaMonthlyReportInvoiceReconciliation;

        $this->assertTrue($reconciliation->isReconciled($row));
        $this->assertSame('0.00', $reconciliation->diagnostic($row)['delta']);
    }

    public function test_header_discount_and_rounding_both_apply(): void
    {
        $row = $this->row(
            taxableAmount: 100.00,
            cgst: 9.00,
            sgst: 9.00,
            headerDiscount: 0.50,
            shortExcess: 0.50,
            invoiceTotal: 118.00,
            invoiceNumber: 'INV-BOTH',
        );

        $reconciliation = new CaMonthlyReportInvoiceReconciliation;

        $this->assertTrue($reconciliation->isReconciled($row));
        $this->assertSame('0.00', $reconciliation->diagnostic($row)['delta']);
    }

    public function test_one_paisa_tolerance_is_unchanged(): void
    {
        $reconciliation = new CaMonthlyReportInvoiceReconciliation;

        $this->assertSame(0.01, CaMonthlyReportInvoiceReconciliation::TOLERANCE);
        $this->assertTrue($reconciliation->isReconciled($this->row(
            taxableAmount: 100.00,
            cgst: 9.00,
            sgst: 9.00,
            invoiceTotal: 118.01,
            invoiceNumber: 'INV-TOL',
        )));
        $this->assertFalse($reconciliation->isReconciled($this->row(
            taxableAmount: 100.00,
            cgst: 9.00,
            sgst: 9.00,
            invoiceTotal: 118.02,
            invoiceNumber: 'INV-TOL-FAIL',
        )));
    }

    private function row(
        float $taxableAmount,
        float $shippingAmount = 0.0,
        float $igst = 0.0,
        float $cgst = 0.0,
        float $sgst = 0.0,
        float $shortExcess = 0.0,
        float $headerDiscount = 0.0,
        float $invoiceTotal = 0.0,
        string $invoiceNumber = 'INV-TEST',
    ): CaMonthlyReportInvoiceExportRow {
        return new CaMonthlyReportInvoiceExportRow(
            parentCells: ['', '', $invoiceNumber, '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            detailRows: [],
            expandable: false,
            taxableAmount: $taxableAmount,
            shippingAmount: $shippingAmount,
            igst: $igst,
            cgst: $cgst,
            sgst: $sgst,
            shortExcess: $shortExcess,
            headerDiscount: $headerDiscount,
            invoiceTotal: $invoiceTotal,
        );
    }
}
