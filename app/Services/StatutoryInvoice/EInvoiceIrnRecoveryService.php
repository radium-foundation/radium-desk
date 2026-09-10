<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\EInvoiceRecordStatus;
use App\Enums\EInvoiceSubmitOutcome;
use App\Models\EInvoiceRecord;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\EInvoiceIrnPayload;
use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;
use App\Services\StatutoryInvoice\Whitebooks\WhitebooksIrnRecoveryGateway;
use Illuminate\Support\Facades\DB;

/**
 * Explicit Get-IRN recovery. Never calls GENERATE / submit().
 * Not invoked by the outbox worker. Provider/worker flags do not enable this path.
 */
final class EInvoiceIrnRecoveryService
{
    public function __construct(
        private readonly WhitebooksIrnRecoveryGateway $recovery,
        private readonly EInvoiceIrnPayloadMapper $mapper,
        private readonly EInvoiceSignedInvoiceStore $signedInvoices,
    ) {}

    public function recover(StatutoryInvoice $invoice): EInvoiceSubmitResult
    {
        $payload = $this->mapper->map($invoice);
        $result = $this->recovery->fetchExisting($invoice, $payload);

        DB::transaction(function () use ($invoice, $payload, $result): void {
            $this->persistRecovery($invoice, $payload, $result);
        });

        return $result;
    }

    private function persistRecovery(
        StatutoryInvoice $invoice,
        EInvoiceIrnPayload $payload,
        EInvoiceSubmitResult $result,
    ): void {
        $existing = EInvoiceRecord::query()
            ->where('invoice_id', $invoice->id)
            ->lockForUpdate()
            ->first();
        if (EInvoiceIrnGuard::recordHasIssuedIrn($existing)) {
            if ($result->outcome === EInvoiceSubmitOutcome::Success) {
                $this->signedInvoices->persistFromResult($invoice, $result);
            }

            return;
        }

        match ($result->outcome) {
            EInvoiceSubmitOutcome::Success => $this->persistSuccess($invoice, $payload, $result),
            EInvoiceSubmitOutcome::IrnNotFound => $this->writeRecord($invoice, $payload, $result, EInvoiceRecordStatus::IrnNotFound),
            EInvoiceSubmitOutcome::TemporaryFailure => $this->writeRecord($invoice, $payload, $result, EInvoiceRecordStatus::Ambiguous),
            EInvoiceSubmitOutcome::PermanentFailure => $this->writeRecord($invoice, $payload, $result, EInvoiceRecordStatus::PermanentFailure),
            EInvoiceSubmitOutcome::Ambiguous => $this->writeRecord($invoice, $payload, $result, EInvoiceRecordStatus::Ambiguous),
            EInvoiceSubmitOutcome::Skipped => $this->writeRecord($invoice, $payload, $result, EInvoiceRecordStatus::Skipped),
        };
    }

    private function persistSuccess(
        StatutoryInvoice $invoice,
        EInvoiceIrnPayload $payload,
        EInvoiceSubmitResult $result,
    ): void {
        if (! EInvoiceIrnGuard::isIssuedIrn($result->irn)) {
            $this->writeRecord($invoice, $payload, $result, EInvoiceRecordStatus::PermanentFailure);

            return;
        }

        $this->writeRecord($invoice, $payload, $result, EInvoiceRecordStatus::Submitted, [
            'irn' => trim((string) $result->irn),
            'ack_no' => $result->ackNo,
            'ack_date' => $result->ackDate,
            'signed_qr' => $result->signedQr,
        ]);
        $this->signedInvoices->persistFromResult($invoice, $result);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function writeRecord(
        StatutoryInvoice $invoice,
        EInvoiceIrnPayload $payload,
        EInvoiceSubmitResult $result,
        EInvoiceRecordStatus $status,
        array $extra = [],
    ): void {
        $existing = EInvoiceRecord::query()
            ->where('invoice_id', $invoice->id)
            ->lockForUpdate()
            ->first();
        if (EInvoiceIrnGuard::recordHasIssuedIrn($existing)) {
            return;
        }

        EInvoiceRecord::query()->updateOrCreate(
            ['invoice_id' => $invoice->id],
            EInvoiceIrnGuard::attributesWithoutClearingIssuedIrn([
                'provider' => $result->provider,
                'status' => $status->value,
                'request_payload' => $payload->toArray(),
                'response_payload' => [
                    'outcome' => $result->outcome->value,
                    'provider_status' => $result->status,
                    'correlation_id' => $result->correlationId,
                    'payload' => $result->payload,
                ],
                ...$extra,
            ]),
        );
    }
}
