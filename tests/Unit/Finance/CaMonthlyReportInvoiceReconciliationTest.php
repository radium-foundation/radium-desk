<?php

namespace Tests\Unit\Finance;

use App\Reports\CaMonthly\CaMonthlyReportInvoiceExportRow;
use App\Reports\CaMonthly\CaMonthlyReportInvoiceReconciliation;
use Tests\TestCase;

class CaMonthlyReportInvoiceReconciliationTest extends TestCase
{
    public function test_reconciled_invoice_passes_identity_check(): void
    {
        $row = new CaMonthlyReportInvoiceExportRow(
            parentCells: ['', '', 'INV-1', '', '', '', '', '', '', '', '', '100.00', '', '', '9.00', '9.00', '', '118.00', '', '', ''],
            detailRows: [],
            expandable: false,
            taxableAmount: 100.0,
            shippingAmount: 0.0,
            igst: 0.0,
            cgst: 9.0,
            sgst: 9.0,
            shortExcess: 0.0,
            invoiceTotal: 118.0,
        );

        $reconciliation = new CaMonthlyReportInvoiceReconciliation;

        $this->assertTrue($reconciliation->isReconciled($row));
        $this->assertSame('0.00', $reconciliation->diagnostic($row)['delta']);
    }

    public function test_non_reconciled_invoice_reports_delta(): void
    {
        $row = new CaMonthlyReportInvoiceExportRow(
            parentCells: ['', '', 'INV-2', '', '', '', '', '', '', '', '', '100.00', '', '', '9.00', '9.00', '', '120.00', '', '', ''],
            detailRows: [],
            expandable: false,
            taxableAmount: 100.0,
            shippingAmount: 0.0,
            igst: 0.0,
            cgst: 9.0,
            sgst: 9.0,
            shortExcess: 0.0,
            invoiceTotal: 120.0,
        );

        $reconciliation = new CaMonthlyReportInvoiceReconciliation;

        $this->assertFalse($reconciliation->isReconciled($row));
        $this->assertSame('-2.00', $reconciliation->diagnostic($row)['delta']);
    }
}
