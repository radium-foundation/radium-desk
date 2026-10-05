<?php

namespace App\Reports\CaMonthly;

use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\BuyerGstin;

final class CaMonthlyReportCustomerTypeDisplay
{
    public static function forInvoice(StatutoryInvoice $invoice): string
    {
        $gstin = BuyerGstin::normalize($invoice->buyer_gstin);

        return $gstin !== null && BuyerGstin::isValid($gstin) ? 'B2B' : 'B2C';
    }
}
