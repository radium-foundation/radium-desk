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
        private readonly EInvoiceSignedInvoiceStore $signedInvoices,
    ) {}

    public function process(OutboxEvent $event): void
    {
        $invoiceId = (int) ($event->payload['invoice_id'] ?? 0);
        $invoice = StatutoryInvoice::query()->find($invoiceId);
        if ($invoice === null) {
            return;
        }

        $decision = $this->claim($invoice);
        if ($decision['kind'] === 'done') {
            return;
        }

        $payload = $decision['payload'] ?? $this->mapper->map($invoice);

        if ($decision['kind'] === 'recover') {
            $this->runRecovery($invoice, $payload);

            return;
        }

        $this->runGenerate($invoice, $payload);
    }

    /**
     * Short lock: decide GENERATE vs recover, and commit Processing before HTTP.
     *
     * @return array{kind: 'done'|'generate'|'recover', payload?: EInvoiceIrnPayload}
     */
    private function claim(StatutoryInvoice $invoice): array
    {
        return DB::transaction(function () use ($invoice): array {
            StatutoryInvoice::query()->whereKey($invoice->id)->lockForUpdate()->first();
            $record = $this->lockedRecord($invoice);
            if (EInvoiceIrnGuard::recordHasIssuedIrn($record) || EInvoiceIrnGuard::mustNotResubmit($record)) {
                return ['kind' => 'done'];
            }
            if (EInvoiceIrnGuard::mustRecoverInsteadOfGenerate($record)) {
                return ['kind' => 'recover'];
            }

            $decision = $this->eligibility->evaluate($invoice);
            if (! $decision->eligible) {
                $this->persistSkip($invoice, $decision->reason);

                return ['kind' => 'done'];
            }

            if (! $this->submissionEnabled()) {
                $this->persistSkip($invoice, $this->skipReason());

                return ['kind' => 'done'];
            }

            $payload = $this->mapper->map($invoice);
            if (! $payload->isSubmittable()) {
                $this->persistSkip($invoice, 'irp_fields_incomplete', $payload);

                return ['kind' => 'done'];
            }

            $record = $this->lockedRecord($invoice);
            if (EInvoiceIrnGuard::recordHasIssuedIrn($record) || EInvoiceIrnGuard::mustNotResubmit($record)) {
                return ['kind' => 'done'];
            }
            if (EInvoiceIrnGuard::mustRecoverInsteadOfGenerate($record)) {
                return ['kind' => 'recover', 'payload' => $payload];
            }

            $this->writeRecord($invoice, [
                'provider' => $this->gateway->provider(),
                'status' => EInvoiceRecordStatus::Processing->value,
                'request_payload' => $payload->toArray(),
            ]);

            return ['kind' => 'generate', 'payload' => $payload];
        });
    }

    private function runGenerate(StatutoryInvoice $invoice, EInvoiceIrnPayload $payload): void
    {
        try {
            $result = $this->gateway->submit($invoice, $payload);
        } catch (Throwable) {
            $this->persistInterrupted($invoice, $payload, 'generate_interrupted');
            throw new EInvoiceRecoveryRequiredException(
                'E-invoice GENERATE was interrupted; Get-IRN recovery is required.',
            );
        }

        if ($result->outcome === EInvoiceSubmitOutcome::TemporaryFailure) {
            $this->persistLocal($invoice, $payload, $result);
            throw new EInvoiceTemporaryFailureException(
                'E-invoice provider returned a pre-submit temporary failure.',
            );
        }

        $this->persistLocal($invoice, $payload, $result);
        $this->afterPersist($invoice, $result);
    }

    private function runRecovery(StatutoryInvoice $invoice, EInvoiceIrnPayload $payload): void
    {
        if (! $this->submissionEnabled()) {
            return;
        }

        try {
            $result = $this->gateway->fetchExisting($invoice, $payload);
        } catch (Throwable) {
            $this->persistInterrupted($invoice, $payload, 'get_irn_interrupted');
            throw new EInvoiceRecoveryRequiredException(
                'E-invoice Get-IRN was interrupted; recovery remains required.',
            );
        }

        if ($result->outcome === EInvoiceSubmitOutcome::TemporaryFailure
            || $result->outcome === EInvoiceSubmitOutcome::Ambiguous) {
            $this->persistLocal($invoice, $payload, $this->asRecoverableAmbiguous($result));
            throw new EInvoiceRecoveryRequiredException(
                'E-invoice Get-IRN did not settle; recovery remains required.',
            );
        }

        $this->persistLocal($invoice, $payload, $result);
        $this->afterPersist($invoice, $result);
    }

    private function afterPersist(StatutoryInvoice $invoice, EInvoiceSubmitResult $result): void
    {
        if ($result->outcome === EInvoiceSubmitOutcome::Success && EInvoiceIrnGuard::isIssuedIrn($result->irn)) {
            $this->finalizePdf($invoice);

            return;
        }

        if ($result->outcome === EInvoiceSubmitOutcome::Ambiguous) {
            throw new EInvoiceRecoveryRequiredException(
                'E-invoice GENERATE is ambiguous; Get-IRN recovery is required.',
            );
        }
    }

    private function asRecoverableAmbiguous(EInvoiceSubmitResult $result): EInvoiceSubmitResult
    {
        $payload = is_array($result->payload) ? $result->payload : ['detail' => $result->payload];

        return EInvoiceSubmitResult::ambiguous(
            $result->provider,
            $payload,
            $result->correlationId,
        );
    }

    private function persistLocal(
        StatutoryInvoice $invoice,
        EInvoiceIrnPayload $payload,
        EInvoiceSubmitResult $result,
    ): void {
        try {
            DB::transaction(function () use ($invoice, $payload, $result): void {
                $this->persistResult($invoice, $payload, $result);
            });
        } catch (EInvoiceRecoveryRequiredException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->persistInterrupted($invoice, $payload, 'persist_interrupted');
            throw new EInvoiceRecoveryRequiredException(
                'E-invoice persistence failed after WhiteBooks; Get-IRN recovery is required.',
            );
        }
    }

    private function persistInterrupted(StatutoryInvoice $invoice, EInvoiceIrnPayload $payload, string $reason): void
    {
        DB::transaction(function () use ($invoice, $payload, $reason): void {
            StatutoryInvoice::query()->whereKey($invoice->id)->lockForUpdate()->first();
            $locked = $this->lockedRecord($invoice);
            if (EInvoiceIrnGuard::recordHasIssuedIrn($locked)) {
                return;
            }

            $this->writeRecord($invoice, [
                'provider' => $this->gateway->provider(),
                'status' => EInvoiceRecordStatus::Ambiguous->value,
                'request_payload' => $payload->toArray(),
                'response_payload' => [
                    'outcome' => EInvoiceSubmitOutcome::Ambiguous->value,
                    'payload' => ['reason' => $reason],
                ],
            ]);
        });
    }

    private function persistResult(
        StatutoryInvoice $invoice,
        EInvoiceIrnPayload $payload,
        EInvoiceSubmitResult $result,
    ): void {
        $locked = $this->lockedRecord($invoice);
        if (EInvoiceIrnGuard::recordHasIssuedIrn($locked)) {
            if ($result->outcome === EInvoiceSubmitOutcome::Success) {
                $this->signedInvoices->persistFromResult($invoice, $result);
            }

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
            EInvoiceSubmitOutcome::IrnNotFound => $this->persistOutcome(
                $invoice,
                $payload,
                $result,
                EInvoiceRecordStatus::IrnNotFound,
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
        $this->signedInvoices->persistFromResult($invoice, $result);
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
        $existing = $this->lockedRecord($invoice);
        if (EInvoiceIrnGuard::recordHasIssuedIrn($existing)) {
            return;
        }

        EInvoiceRecord::query()->updateOrCreate(
            ['invoice_id' => $invoice->id],
            EInvoiceIrnGuard::attributesWithoutClearingIssuedIrn($attributes),
        );
    }

    private function lockedRecord(StatutoryInvoice $invoice): ?EInvoiceRecord
    {
        return EInvoiceRecord::query()
            ->where('invoice_id', $invoice->id)
            ->lockForUpdate()
            ->first();
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
