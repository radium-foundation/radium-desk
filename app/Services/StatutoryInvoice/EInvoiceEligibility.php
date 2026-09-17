<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\EInvoiceEligibilityResult;

class EInvoiceEligibility
{
    public function __construct(
        private readonly EInvoiceIssuancePolicy $issuancePolicy,
    ) {}

    public function evaluate(StatutoryInvoice $invoice): EInvoiceEligibilityResult
    {
        if ($invoice->status === StatutoryInvoiceStatus::Cancelled) {
            return new EInvoiceEligibilityResult(false, 'invoice_cancelled');
        }

        if ($invoice->status !== StatutoryInvoiceStatus::Issued) {
            return new EInvoiceEligibilityResult(false, 'invalid_invoice_status');
        }

        if ($invoice->document_type !== StatutoryInvoiceDocumentType::TaxInvoice) {
            return new EInvoiceEligibilityResult(false, 'unsupported_document_type');
        }

        if (! StatutoryInvoiceScope::contains($invoice->issued_at)) {
            return new EInvoiceEligibilityResult(false, 'outside_invoice_scope');
        }

        $buyerGstin = BuyerGstin::normalize($invoice->buyer_gstin);
        if ($buyerGstin === null) {
            return new EInvoiceEligibilityResult(false, 'b2c_not_eligible');
        }

        if (! BuyerGstin::isValid($buyerGstin)) {
            return new EInvoiceEligibilityResult(false, 'invalid_buyer_gstin');
        }

        $gstGaps = EInvoiceStoredGstGuard::missingReasons($invoice);
        if ($gstGaps !== []) {
            return new EInvoiceEligibilityResult(false, 'incomplete_gst');
        }

        $policy = $this->issuancePolicy->evaluate($invoice);
        if (! $policy->permitted) {
            return new EInvoiceEligibilityResult(false, $policy->reason);
        }

        return new EInvoiceEligibilityResult(true, $policy->reason);
    }
}
