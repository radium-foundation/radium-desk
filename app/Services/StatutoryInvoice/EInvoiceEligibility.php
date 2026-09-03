<?php

namespace App\Services\StatutoryInvoice;

use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\EInvoiceEligibilityResult;

class EInvoiceEligibility
{
    public function evaluate(StatutoryInvoice $invoice): EInvoiceEligibilityResult
    {
        $buyerGstin = BuyerGstin::normalize($invoice->buyer_gstin);
        if ($buyerGstin === null) {
            return new EInvoiceEligibilityResult(false, 'b2c_not_eligible');
        }

        if (! BuyerGstin::isValid($buyerGstin)) {
            return new EInvoiceEligibilityResult(false, 'invalid_buyer_gstin');
        }

        return new EInvoiceEligibilityResult(true, 'b2b_eligible');
    }
}
