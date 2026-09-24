<?php

namespace App\Services\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Models\EInvoiceRecord;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\EInvoiceCancelResult;

final class StatutoryInvoiceIrnCancellationService
{
    public function __construct(
        private readonly EInvoiceGateway $gateway,
    ) {}

    public function cancelIfRequired(StatutoryInvoice $invoice, string $reason): EInvoiceCancelResult
    {
        $invoice->loadMissing('eInvoiceRecord');
        $record = $invoice->eInvoiceRecord;

        if ($record === null || ! $record->hasIssuedIrn()) {
            return EInvoiceCancelResult::notRequired($this->gateway->provider(), [
                'reason' => 'no_issued_irn',
            ]);
        }

        if ($this->irnAlreadyCancelled($record)) {
            return EInvoiceCancelResult::alreadyCancelled(
                $this->gateway->provider(),
                $record->irn,
                ['reason' => 'irn_already_cancelled_locally'],
            );
        }

        $result = $this->gateway->cancel($invoice, $reason);

        if ($result->succeeded()) {
            $this->persistCancellationEvidence($record, $result);
        }

        return $result;
    }

    private function irnAlreadyCancelled(EInvoiceRecord $record): bool
    {
        $payload = $record->response_payload;

        return is_array($payload) && ($payload['irn_cancelled'] ?? false) === true;
    }

    private function persistCancellationEvidence(EInvoiceRecord $record, EInvoiceCancelResult $result): void
    {
        $payload = is_array($record->response_payload) ? $record->response_payload : [];
        $payload['irn_cancelled'] = true;
        $payload['irn_cancelled_at'] = now()->toIso8601String();
        $payload['irn_cancel_outcome'] = $result->outcome->value;
        if ($result->payload !== null) {
            $payload['irn_cancel_response'] = $result->payload;
        }
        if ($result->correlationId !== null) {
            $payload['irn_cancel_correlation_id'] = $result->correlationId;
        }

        $record->update(['response_payload' => $payload]);
    }
}
