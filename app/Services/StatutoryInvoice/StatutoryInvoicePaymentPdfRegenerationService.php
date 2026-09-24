<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceStatus;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceDocument;

class StatutoryInvoicePaymentPdfRegenerationService
{
    public function __construct(
        private readonly StatutoryDocumentService $documents,
    ) {}

    public function regenerateAfterPayment(StatutoryInvoice $invoice): ?StatutoryInvoiceDocument
    {
        $invoice->loadMissing(['items', 'eInvoiceRecord']);

        if ($invoice->status === StatutoryInvoiceStatus::Cancelled) {
            return null;
        }

        return $this->documents->regeneratePresentation($invoice);
    }
}
