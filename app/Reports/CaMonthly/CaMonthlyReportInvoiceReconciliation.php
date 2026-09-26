<?php

namespace App\Reports\CaMonthly;

/**
 * Invoice-grain reconciliation for CA Monthly Report preflight.
 *
 * Authoritative identity check only — does not mutate stored invoice amounts.
 */
final class CaMonthlyReportInvoiceReconciliation
{
    public const TOLERANCE = 0.01;

    public function isReconciled(CaMonthlyReportInvoiceExportRow $row): bool
    {
        return abs($this->delta($row)) <= self::TOLERANCE;
    }

    public function calculatedTotal(CaMonthlyReportInvoiceExportRow $row): float
    {
        return round(
            $row->taxableAmount
            - $row->headerDiscount
            + $row->shippingAmount
            + $row->igst
            + $row->cgst
            + $row->sgst
            + $row->shortExcess,
            2,
        );
    }

    public function delta(CaMonthlyReportInvoiceExportRow $row): float
    {
        return round($this->calculatedTotal($row) - $row->invoiceTotal, 2);
    }

    /**
     * @return array{
     *     invoice_number: string,
     *     calculated_total: string,
     *     invoice_total: string,
     *     delta: string,
     *     taxable_amount: string,
     *     shipping_amount: string,
     *     igst: string,
     *     cgst: string,
     *     sgst: string,
     *     short_excess: string,
     *     header_discount: string,
     * }
     */
    public function diagnostic(CaMonthlyReportInvoiceExportRow $row): array
    {
        return [
            'invoice_number' => (string) ($row->parentCells[2] ?? ''),
            'calculated_total' => $this->money($this->calculatedTotal($row)),
            'invoice_total' => $this->money($row->invoiceTotal),
            'delta' => $this->money($this->delta($row)),
            'taxable_amount' => $this->money($row->taxableAmount),
            'shipping_amount' => $this->money($row->shippingAmount),
            'igst' => $this->money($row->igst),
            'cgst' => $this->money($row->cgst),
            'sgst' => $this->money($row->sgst),
            'short_excess' => $this->money($row->shortExcess),
            'header_discount' => $this->money($row->headerDiscount),
        ];
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
