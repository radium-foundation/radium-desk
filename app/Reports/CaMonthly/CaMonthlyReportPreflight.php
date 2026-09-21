<?php

namespace App\Reports\CaMonthly;

final class CaMonthlyReportPreflight
{
    public function __construct(
        public readonly int $invoiceCount,
        public readonly int $lineCount,
        public readonly int $cancelledInvoiceCount,
        public readonly int $missingHsnSacLineCount,
        public readonly int $missingBuyerGstinInvoiceCount,
        public readonly int $missingIrnInvoiceCount,
        public readonly int $missingStateInvoiceCount,
        public readonly int $unresolvedEwayBillLineCount,
        public readonly int $unresolvedShippingLineCount,
        public readonly int $unresolvedOrdertypeLineCount,
        public readonly int $unresolvedShortExcessLineCount,
        public readonly int $unresolvedAmountLineCount,
    ) {}

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];

        if ($this->lineCount === 0) {
            $warnings[] = 'No statutory invoice lines matched the selected date range.';
        }

        if ($this->cancelledInvoiceCount > 0) {
            $warnings[] = $this->cancelledInvoiceCount.' cancelled invoice(s) are included (Status column). CA confirmation is still required on cancellation treatment.';
        }

        if ($this->missingHsnSacLineCount > 0) {
            $warnings[] = $this->missingHsnSacLineCount.' line(s) are missing SAC/HSN.';
        }

        if ($this->missingBuyerGstinInvoiceCount > 0) {
            $warnings[] = $this->missingBuyerGstinInvoiceCount.' invoice(s) have no buyer GSTIN (may be valid for B2C).';
        }

        if ($this->missingIrnInvoiceCount > 0) {
            $warnings[] = $this->missingIrnInvoiceCount.' invoice(s) have no IRN on file.';
        }

        if ($this->missingStateInvoiceCount > 0) {
            $warnings[] = $this->missingStateInvoiceCount.' invoice(s) have no buyer STATE resolved from billing snapshot or source order.';
        }

        if ($this->unresolvedEwayBillLineCount > 0) {
            $warnings[] = $this->unresolvedEwayBillLineCount.' line(s) have no eWay Bill (Desk does not store eWay Bill; AWB is not substituted).';
        }

        if ($this->unresolvedShippingLineCount > 0) {
            $warnings[] = $this->unresolvedShippingLineCount.' line(s) have no Shipping amount (no authoritative Desk source).';
        }

        if ($this->unresolvedOrdertypeLineCount > 0) {
            $warnings[] = $this->unresolvedOrdertypeLineCount.' line(s) have no Ordertype (CA mapping not defined in Desk).';
        }

        if ($this->unresolvedShortExcessLineCount > 0) {
            $warnings[] = $this->unresolvedShortExcessLineCount.' line(s) have no Short/Excess (line allocation not defined).';
        }

        if ($this->unresolvedAmountLineCount > 0) {
            $warnings[] = $this->unresolvedAmountLineCount.' line(s) have no Amount (semantics unresolved; not fabricated).';
        }

        $warnings[] = 'Period filter uses Date of Invoice (statutory_invoices.issued_at). CA confirmation is still required before treating this as the permanent accounting rule.';

        return $warnings;
    }
}
