<?php

namespace App\Reports\CaMonthly;

use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\StatutoryInvoice;

/**
 * Determines whether a statutory invoice belongs in the CA monthly export.
 */
final class CaMonthlyReportInclusionPolicy
{
    public function includes(StatutoryInvoice $invoice, ?array $allocationTotalsByInvoiceId = null): bool
    {
        if ($invoice->document_type === StatutoryInvoiceDocumentType::CreditNote) {
            return true;
        }

        return in_array($invoice->status, [
            StatutoryInvoiceStatus::Issued,
            StatutoryInvoiceStatus::Cancelled,
        ], true);
    }

    public function exclusionReason(StatutoryInvoice $invoice, ?array $allocationTotalsByInvoiceId = null): ?string
    {
        if ($this->includes($invoice, $allocationTotalsByInvoiceId)) {
            return null;
        }

        return 'unsupported_status';
    }
}
