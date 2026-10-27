<?php

namespace App\Services\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\EInvoiceSubmitOutcome;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\EInvoiceCancelResult;
use App\Services\StatutoryInvoice\Data\IrnCancellationReconciliationResult;
use App\Services\StatutoryInvoice\Whitebooks\WhitebooksResponseMapper;

final class StatutoryInvoiceIrnCancellationReconciliationService
{
    public function __construct(
        private readonly EInvoiceGateway $gateway,
        private readonly EInvoiceIrnPayloadMapper $payloadMapper,
        private readonly WhitebooksResponseMapper $whitebooksResponses,
    ) {}

    public function reconcile(StatutoryInvoice $invoice): IrnCancellationReconciliationResult
    {
        $invoice->loadMissing('eInvoiceRecord');

        if ($invoice->eInvoiceRecord !== null && $this->irnCancelledLocally($invoice->eInvoiceRecord)) {
            return IrnCancellationReconciliationResult::cancelled(
                EInvoiceCancelResult::alreadyCancelled(
                    $this->gateway->provider(),
                    $invoice->eInvoiceRecord->irn,
                    ['reason' => 'irn_already_cancelled_locally'],
                ),
                'Local e-invoice record already marks IRN as cancelled.',
            );
        }

        $payload = $this->payloadMapper->map($invoice);
        $fetch = $this->gateway->fetchExisting($invoice, $payload);

        if ($fetch->outcome === EInvoiceSubmitOutcome::Success) {
            $payloadBody = is_array($fetch->payload) ? $fetch->payload : [];
            if ($this->providerPayloadIndicatesCancelled($payloadBody)) {
                return IrnCancellationReconciliationResult::cancelled(
                    EInvoiceCancelResult::success(
                        $this->gateway->provider(),
                        is_string($fetch->irn) && $fetch->irn !== 'cancelled' ? $fetch->irn : $invoice->eInvoiceRecord?->irn,
                        ['reason' => 'reconciled_via_get_irn', 'source' => 'fetch'],
                        $fetch->correlationId,
                    ),
                    'Provider Get-IRN indicates cancellation.',
                );
            }

            return IrnCancellationReconciliationResult::active(
                'Provider Get-IRN shows an active IRN.',
            );
        }

        if ($fetch->outcome === EInvoiceSubmitOutcome::IrnNotFound) {
            // P-196: errorCode 2154 means IRN-not-found for the document lookup — not proof of
            // cancellation (wrong doc identity, never issued, provider inconsistency, etc.).
            return IrnCancellationReconciliationResult::unknown(
                'Get-IRN returned not-found (2154); manual review required before treating IRN as cancelled.',
            );
        }

        return IrnCancellationReconciliationResult::unknown(
            'Provider status could not be determined from Get-IRN.',
        );
    }

    private function irnCancelledLocally(\App\Models\EInvoiceRecord $record): bool
    {
        $payload = $record->response_payload;

        return is_array($payload) && ($payload['irn_cancelled'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function providerPayloadIndicatesCancelled(array $payload): bool
    {
        if ($this->whitebooksResponses->irnIsCancelledInFetchBody($payload)) {
            return true;
        }

        $cancelDate = $payload['CancelDate'] ?? $payload['CancelDt'] ?? null;
        $status = $payload['Status'] ?? null;

        return (is_string($cancelDate) && trim($cancelDate) !== '')
            || in_array($status, ['CNL', 'CAN', 'Cancelled'], true);
    }
}
