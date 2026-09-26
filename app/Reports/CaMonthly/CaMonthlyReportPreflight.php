<?php

namespace App\Reports\CaMonthly;

final class CaMonthlyReportPreflight
{
    public function __construct(
        public readonly int $invoiceCount,
        public readonly int $lineCount,
        public readonly int $hardwareOrderCount,
        public readonly int $serviceOrderCount,
        public readonly int $bundledOrderCount,
        public readonly int $unclassifiedOrderCount,
        public readonly int $cancelledIncludedCount,
        public readonly int $cancelledExcludedCount,
        public readonly int $cancelledIncludedViaPaymentReferenceCount,
        public readonly int $cancelledIncludedViaPaymentMethodCount,
        public readonly int $cancelledIncludedViaPaymentAllocationCount,
        public readonly int $cancelledAmbiguousInvoiceValueOnlyCount,
        public readonly int $creditNoteCount,
        public readonly int $missingOrderDateCount,
        public readonly int $missingHsnSacLineCount,
        public readonly int $missingBuyerGstinInvoiceCount,
        public readonly int $missingIrnInvoiceCount,
        public readonly int $missingAcknowledgementInvoiceCount,
        public readonly int $missingStateInvoiceCount,
        public readonly int $unresolvedEwayBillLineCount,
        public readonly int $unresolvedShippingLineCount,
        public readonly int $unclassifiedOrdertypeLineCount,
        public readonly int $discountLineCount,
        public readonly int $nonReconcilingLineCount,
        public readonly string $taxableAmountTotal,
        public readonly string $shippingAmountTotal,
        public readonly string $igstTotal,
        public readonly string $cgstTotal,
        public readonly string $sgstTotal,
        public readonly string $shortExcessTotal,
        public readonly string $totalAmountTotal,
        /** @var list<array{invoice_number: string, calculated_total: string, invoice_total: string, delta: string, taxable_amount: string, shipping_amount: string, igst: string, cgst: string, sgst: string, short_excess: string}> */
        public readonly array $nonReconcilingInvoices = [],
    ) {}

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];

        if ($this->lineCount === 0) {
            $warnings[] = 'No statutory invoice lines matched the selected date range and inclusion rules.';
        }

        if ($this->cancelledIncludedCount > 0) {
            $warnings[] = $this->cancelledIncludedCount.' cancelled invoice(s) are included with original invoice values preserved.';
        }

        if ($this->cancelledIncludedViaPaymentReferenceCount > 0) {
            $warnings[] = $this->cancelledIncludedViaPaymentReferenceCount.' cancelled invoice(s) have payment reference evidence on file.';
        }

        if ($this->cancelledIncludedViaPaymentMethodCount > 0) {
            $warnings[] = $this->cancelledIncludedViaPaymentMethodCount.' cancelled invoice(s) have payment method evidence on file.';
        }

        if ($this->cancelledIncludedViaPaymentAllocationCount > 0) {
            $warnings[] = $this->cancelledIncludedViaPaymentAllocationCount.' cancelled invoice(s) have Service POS payment allocation evidence on file.';
        }

        if ($this->cancelledAmbiguousInvoiceValueOnlyCount > 0) {
            $warnings[] = $this->cancelledAmbiguousInvoiceValueOnlyCount.' cancelled invoice(s) have invoice value > 0 but no authoritative payment evidence on file.';
        }

        if ($this->creditNoteCount > 0) {
            $warnings[] = $this->creditNoteCount.' credit note row(s) are included.';
        }

        if ($this->unclassifiedOrderCount > 0) {
            $warnings[] = $this->unclassifiedOrderCount.' invoice(s) could not be authoritatively classified as Hardware, Service, or Bundled.';
        }

        if ($this->missingOrderDateCount > 0) {
            $warnings[] = $this->missingOrderDateCount.' line(s) are missing Date_of_order.';
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

        if ($this->missingAcknowledgementInvoiceCount > 0) {
            $warnings[] = $this->missingAcknowledgementInvoiceCount.' invoice(s) have no acknowledgement number on file.';
        }

        if ($this->missingStateInvoiceCount > 0) {
            $warnings[] = $this->missingStateInvoiceCount.' invoice(s) have no buyer STATE resolved from billing snapshot.';
        }

        if ($this->unresolvedEwayBillLineCount > 0) {
            $warnings[] = $this->unresolvedEwayBillLineCount.' line(s) have no eWay Bill (Desk does not store eWay Bill; AWB is not substituted).';
        }

        if ($this->unresolvedShippingLineCount > 0) {
            $warnings[] = $this->unresolvedShippingLineCount.' line(s) have no Shipping amount (historical invoices default to zero until upstream shipping is supplied at mint).';
        }

        if ($this->discountLineCount > 0) {
            $warnings[] = $this->discountLineCount.' line(s) include a stored line discount reflected in Taxable Amount / Amount.';
        }

        if ($this->nonReconcilingLineCount > 0) {
            $warnings[] = $this->nonReconcilingLineCount.' line(s) do not reconcile: Total Amount ≠ Taxable Amount + Shipping + GST + Short/Excess.';
        }

        $warnings[] = 'Period filter uses Date of Invoice (statutory_invoices.issued_at).';

        return $warnings;
    }
}
