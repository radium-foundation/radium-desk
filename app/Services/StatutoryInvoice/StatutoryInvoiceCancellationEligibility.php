<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\EInvoiceRecord;
use App\Models\StatutoryInvoice;
use Illuminate\Validation\ValidationException;

final class StatutoryInvoiceCancellationEligibility
{
    public function assertCanCancel(StatutoryInvoice $invoice): void
    {
        if ($invoice->status === StatutoryInvoiceStatus::Cancelled) {
            return;
        }

        if ($invoice->status !== StatutoryInvoiceStatus::Issued) {
            throw ValidationException::withMessages([
                'invoice' => 'Only an issued statutory invoice can be cancelled.',
            ]);
        }

        if ($invoice->document_type !== StatutoryInvoiceDocumentType::TaxInvoice) {
            throw ValidationException::withMessages([
                'invoice' => 'Only tax invoices can be cancelled through this workflow.',
            ]);
        }
    }

    public function irnCancellationRequired(StatutoryInvoice $invoice): bool
    {
        $record = $invoice->eInvoiceRecord;
        if ($record === null || ! $record->hasIssuedIrn()) {
            return false;
        }

        if ($this->irnAlreadyCancelledLocally($record)) {
            return false;
        }

        return $record->status === EInvoiceRecordStatus::Submitted->value;
    }

    public function inventoryReversalApplicable(StatutoryInvoice $invoice): bool
    {
        return $invoice->inventory_sale_id !== null;
    }

    /**
     * @param  array<string, mixed>|null  $responsePayload
     */
    private function irnAlreadyCancelledLocally(?EInvoiceRecord $record): bool
    {
        if ($record === null) {
            return false;
        }

        $payload = $record->response_payload;
        if (! is_array($payload)) {
            return false;
        }

        return ($payload['irn_cancelled'] ?? false) === true;
    }
}
