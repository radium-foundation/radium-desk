<?php

namespace App\Reports\CaMonthly;

use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\StatutoryInvoice;

final class CaMonthlyReportStatusDisplay
{
    public static function forInvoice(StatutoryInvoice $invoice): string
    {
        if ($invoice->document_type === StatutoryInvoiceDocumentType::CreditNote) {
            return 'Credit Note';
        }

        return match ($invoice->status) {
            StatutoryInvoiceStatus::Cancelled => 'Cancelled',
            StatutoryInvoiceStatus::Issued => 'Issued',
            default => ucfirst((string) $invoice->status->value),
        };
    }
}
