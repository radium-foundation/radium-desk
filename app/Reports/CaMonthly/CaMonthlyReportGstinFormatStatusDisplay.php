<?php

namespace App\Reports\CaMonthly;

use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\BuyerGstin;

/**
 * Local GSTIN format validation for CA reporting.
 *
 * Registration status (active/cancelled/suspended) is not verified — no approved
 * external GST taxpayer lookup is bound in the current release.
 */
final class CaMonthlyReportGstinFormatStatusDisplay
{
    public const NOT_PRESENT = 'Not present';

    public const FORMAT_VALID = 'Format valid — registration not verified';

    public const FORMAT_INVALID = 'Format invalid';

    public static function forInvoice(StatutoryInvoice $invoice): string
    {
        $normalized = BuyerGstin::normalize($invoice->buyer_gstin);
        if ($normalized === null) {
            return self::NOT_PRESENT;
        }

        return BuyerGstin::isValid($normalized)
            ? self::FORMAT_VALID
            : self::FORMAT_INVALID;
    }
}
