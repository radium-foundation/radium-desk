<?php

namespace App\Services\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\EInvoiceSubmitOutcome;
use App\Models\EInvoiceRecord;
use App\Models\OutboxEvent;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\EInvoiceIrnPayload;
use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;
use Illuminate\Support\Facades\DB;
use Throwable;

class EInvoiceProcessor
{
    public function __construct(
        private readonly EInvoiceGateway $gateway,
        private readonly EInvoiceEligibility $eligibility,
        private readonly EInvoiceIrnPayloadMapper $mapper,
        private readonly StatutoryDocumentService $documents,
    ) {}

    public function process(OutboxEvent $event): void
    {
        $invoiceId = (int) ($event->payload['invoice_id'] ?? 0);
        $invoice = StatutoryInvoice::query()->find($invoiceId);
        if ($invoice === null) {
            return;
        }

        $outcome = DB::transaction(function () use ($invoice): array {
            $record = EInvoiceRecord::query()
                ->where('invoice_id', $invoice->id)
                ->lockForUpdate()
                ->first();

            if (EInvoiceIrnGuard::recordHasIssuedIrn($record) || EInvoiceIrnGuard::mustNotResubmit($record)) {
                return ['retryable' => false, 'finalize' => false];
            }

            if (EInvoiceIrnGuard::mustRecoverInsteadOfGenerate($record)) {
                return $this->recover($invoice);
            }

            $decision = $this->eligibility->evaluate($invoice);
            if (! $decision->eligible) {
                $this->persistSkip($invoice, $decision->reason);

                return ['retryable' => false, 'finalize' => false];
            }

            if (! $this->submissionEnabled()) {
                $this->persistSkip($invoice, $this->skipReason());

                return ['retryable' => false, 'finalize' => false];
            }

            $payload = $this->mapper->map($invoice);
            if (! $payload->isSubmittable()) {
                $this->persistSkip($invoice, 'irp_fields_incomplete', $payload);

                return ['retryable' => false, 'finalize' => false];
            }

            $record = EInvoiceRecord::query()
                ->where('invoice_id', $invoice->id)
                ->lockForUpdate()
                ->first();
            if (EInvoiceIrnGuard::recordHasIssuedIrn($record) || EInvoiceIrnGuard::mustNotResubmit($record)) {
                return ['retryable' => false, 'finalize' => false];
            }
            if (EInvoiceIrnGuard::mustRecoverInsteadOfGenerate($record)) {
                return $this->recover($invoice, $payload);
            }

            $this->writeRecord($invoice, [
                'provider' => $this->gateway->provider(),
                'status' => EInvoiceRecordStatus::Processing->value,
                'request_payload' => $payload->toArray(),
            ]);

            $result = $this->gateway->submit($invoice, $payload);
            $this->persistResult($invoice, $payload, $result);

            return [
                'retryable' => $result->outcome === EInvoiceSubmitOutcome::TemporaryFailure,
                'finalize' => $result->outcome === EInvoiceSubmitOutcome::Success
                    && EInvoiceIrnGuard::isIssuedIrn($result->irn),
            ];
        });

        if ($outcome['finalize']) {
            $this->finalizePdf($invoice);
        }

        if ($outcome['retryable']) {
            throw new EInvoiceTemporaryFailureException(
                'E-invoice provider returned a temporary failure.',
            );
        }
    }

    /**
     * @return array{retryable: bool, finalize: bool}
     */
    private function recover(StatutoryInvoice $invoice, ?EInvoiceIrnPayload $payload = null): array
    {
        if (! $this->submissionEnabled()) {
            return ['retryable' => false, 'finalize' => false];
        }

        $payload ??= $this->mapper->map($invoice);
        $result = $this->gateway->fetchExisting($invoice, $payload);

        if ($result->outcome === EInvoiceSubmitOutcome::TemporaryFailure) {
            $this->persistOutcome($invoice, $payload, $result, EInvoiceRecordStatus::Ambiguous);

            return ['retryable' => true, 'finalize' => false];
        }

        $this->persistResult($invoice, $payload, $result);

        return [
            'retryable' => false,
            'finalize' => $result->outcome === EInvoiceSubmitOutcome::Success
                && EInvoiceIrnGuard::isIssuedIrn($result->irn),
        ];
    }

    private function persistResult(
        StatutoryInvoice $invoice,
        EInvoiceIrnPayload $payload,
        EInvoiceSubmitResult $result,
    ): void {
        $locked = EInvoiceRecord::query()
            ->where('invoice_id', $invoice->id)
            ->lockForUpdate()
            ->first();
        if (EInvoiceIrnGuard::recordHasIssuedIrn($locked)) {
            return;
        }

        match ($result->outcome) {
            EInvoiceSubmitOutcome::Success => $this->persistSuccess($invoice, $payload, $result),
            EInvoiceSubmitOutcome::TemporaryFailure => $this->persistOutcome(
                $invoice,
                $payload,
                $result,
                EInvoiceRecordStatus::TemporaryFailure,
            ),
            EInvoiceSubmitOutcome::PermanentFailure => $this->persistOutcome(
                $invoice,
                $payload,
                $result,
                EInvoiceRecordStatus::PermanentFailure,
            ),
            EInvoiceSubmitOutcome::Ambiguous => $this->persistOutcome(
                $invoice,
                $payload,
                $result,
                EInvoiceRecordStatus::Ambiguous,
            ),
            EInvoiceSubmitOutcome::Skipped => $this->persistSkip($invoice, 'provider_skipped', $payload),
        };
    }

    private function persistSuccess(
        StatutoryInvoice $invoice,
        EInvoiceIrnPayload $payload,
        EInvoiceSubmitResult $result,
    ): void {
        if (! EInvoiceIrnGuard::isIssuedIrn($result->irn)) {
            $this->persistOutcome($invoice, $payload, $result, EInvoiceRecordStatus::PermanentFailure);

            return;
        }

        $this->writeRecord($invoice, [
            'provider' => $result->provider,
            'irn' => trim((string) $result->irn),
            'ack_no' => $result->ackNo,
            'ack_date' => $result->ackDate,
            'signed_qr' => $result->signedQr,
            'status' => EInvoiceRecordStatus::Submitted->value,
            'request_payload' => $payload->toArray(),
            'response_payload' => $this->responsePayload($result),
        ]);
    }

    private function persistOutcome(
        StatutoryInvoice $invoice,
        EInvoiceIrnPayload $payload,
        EInvoiceSubmitResult $result,
        EInvoiceRecordStatus $status,
    ): void {
        $this->writeRecord($invoice, [
            'provider' => $result->provider,
            'status' => $status->value,
            'request_payload' => $payload->toArray(),
            'response_payload' => $this->responsePayload($result),
        ]);
    }

    private function persistSkip(StatutoryInvoice $invoice, string $reason, ?EInvoiceIrnPayload $payload = null): void
    {
        $attributes = [
            'provider' => $this->gateway->provider(),
            'status' => EInvoiceRecordStatus::Skipped->value,
            'response_payload' => [
                'skip_reason' => $reason,
            ],
        ];
        if ($payload !== null) {
            $attributes['request_payload'] = $payload->toArray();
            $attributes['response_payload']['gaps'] = $payload->gaps;
        }

        $this->writeRecord($invoice, $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function writeRecord(StatutoryInvoice $invoice, array $attributes): void
    {
        $existing = EInvoiceRecord::query()
            ->where('invoice_id', $invoice->id)
            ->lockForUpdate()
            ->first();
        if (EInvoiceIrnGuard::recordHasIssuedIrn($existing)) {
            return;
        }

        EInvoiceRecord::query()->updateOrCreate(
            ['invoice_id' => $invoice->id],
            EInvoiceIrnGuard::attributesWithoutClearingIssuedIrn($attributes),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function responsePayload(EInvoiceSubmitResult $result): array
    {
        return [
            'outcome' => $result->outcome->value,
            'provider_status' => $result->status,
            'correlation_id' => $result->correlationId,
            'payload' => $result->payload,
        ];
    }

    private function finalizePdf(StatutoryInvoice $invoice): void
    {
        try {
            $this->documents->finalizeAfterIrn($invoice->fresh(['items', 'eInvoiceRecord', 'document']) ?? $invoice);
        } catch (Throwable) {
            // IRN persistence must not roll back because PDF rewrite failed.
        }
    }

    private function submissionEnabled(): bool
    {
        if (! (bool) config('statutory_invoices.worker_may_mint')) {
            return false;
        }

        $provider = (string) config('statutory_invoices.einvoice.provider', 'none');
        if ($provider === '' || $provider === 'none') {
            return false;
        }

        return $this->gateway->provider() !== 'none';
    }

    private function skipReason(): string
    {
        if (! (bool) config('statutory_invoices.worker_may_mint')) {
            return 'worker_may_mint_off';
        }

        $provider = (string) config('statutory_invoices.einvoice.provider', 'none');
        if ($provider === '' || $provider === 'none' || $this->gateway->provider() === 'none') {
            return 'provider_disabled';
        }

        return 'provider_http_disabled';
    }
}
