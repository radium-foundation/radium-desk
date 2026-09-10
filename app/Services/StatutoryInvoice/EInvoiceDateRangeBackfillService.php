<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\EInvoiceSubmitOutcome;
use App\Models\StatutoryInvoice;
use Carbon\CarbonImmutable;

/**
 * Bounded, oldest-first IRN backfill. Get-IRN first; GENERATE only after confirmed absence.
 */
final class EInvoiceDateRangeBackfillService
{
    public function __construct(
        private readonly EInvoiceReconciliationService $reconciliation,
        private readonly EInvoiceIrnRecoveryService $recovery,
        private readonly EInvoiceProcessor $processor,
        private readonly EInvoiceEligibility $eligibility,
        private readonly EInvoiceIrnPayloadMapper $mapper,
        private readonly StatutoryDocumentService $documents,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function processRange(
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $recover = true,
        bool $generate = false,
        bool $pdf = false,
        int $limit = 10,
        ?int $invoiceId = null,
    ): array {
        $invoices = $this->reconciliation->invoicesInRange($from, $to);
        $results = [];
        $processed = 0;

        foreach ($invoices as $invoice) {
            if ($invoiceId !== null && (int) $invoice->id !== $invoiceId) {
                continue;
            }

            $classified = $this->reconciliation->classify($invoice);
            if (($recover || $generate) && ! $classified['is_b2b'] && $classified['irn'] === null) {
                continue;
            }

            if ($processed >= $limit) {
                break;
            }

            $results[] = $this->processOne($invoice, $recover, $generate, $pdf);
            $processed++;
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    public function processOne(
        StatutoryInvoice $invoice,
        bool $recover,
        bool $generate,
        bool $pdf,
    ): array {
        $invoice->loadMissing(['items', 'eInvoiceRecord']);
        $classified = $this->reconciliation->classify($invoice);
        $previous = $invoice->eInvoiceRecord?->status;
        $hadIrn = EInvoiceIrnGuard::recordHasIssuedIrn($invoice->eInvoiceRecord);
        $row = [
            'invoice_id' => $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'b2b_eligible' => $classified['eligible'],
            'previous_irn_state' => $previous,
            'recovery_attempted' => false,
            'generate_attempted' => false,
            'result' => 'unchanged',
            'irn' => $classified['irn'],
            'ack_no' => $classified['ack_no'],
            'pdf_regenerated' => false,
            'final_status' => $previous,
            'failure_reason' => $classified['reason_if_no_irn'],
        ];

        if ($hadIrn) {
            if ($recover) {
                $this->recovery->recover($invoice->fresh(['items', 'eInvoiceRecord']) ?? $invoice);
                $row['recovery_attempted'] = true;
            }
            if ($pdf) {
                $row['pdf_regenerated'] = $this->rewritePdf($invoice);
            }
            $fresh = $invoice->fresh('eInvoiceRecord');
            $row['result'] = 'already_had_irn';
            $row['irn'] = $fresh?->eInvoiceRecord?->irn;
            $row['ack_no'] = $fresh?->eInvoiceRecord?->ack_no;
            $row['final_status'] = $fresh?->eInvoiceRecord?->status;
            $row['failure_reason'] = null;

            return $row;
        }

        $mustRecover = EInvoiceIrnGuard::mustRecoverInsteadOfGenerate($invoice->eInvoiceRecord);
        $eligibility = $this->eligibility->evaluate($invoice);
        if (! $mustRecover && ! $eligibility->eligible) {
            $row['result'] = 'not_eligible';
            $row['failure_reason'] = $eligibility->reason;
            if ($pdf) {
                $row['pdf_regenerated'] = $this->rewritePdf($invoice);
            }

            return $row;
        }

        if ($mustRecover) {
            if ($recover) {
                $recovery = $this->recovery->recover($invoice);
                $row['recovery_attempted'] = true;
                $row = $this->applyRecovery($row, $invoice, $recovery->outcome);
            }
            if ($pdf) {
                $row['pdf_regenerated'] = $this->rewritePdf($invoice);
            }

            return $row;
        }

        if ($recover) {
            $recovery = $this->recovery->recover($invoice);
            $row['recovery_attempted'] = true;
            $row = $this->applyRecovery($row, $invoice, $recovery->outcome);
            $invoice = $invoice->fresh(['items', 'eInvoiceRecord']) ?? $invoice;
            if (EInvoiceIrnGuard::recordHasIssuedIrn($invoice->eInvoiceRecord)) {
                if ($pdf) {
                    $row['pdf_regenerated'] = $this->rewritePdf($invoice);
                }

                return $row;
            }
            if ($recovery->outcome === EInvoiceSubmitOutcome::Ambiguous
                || $recovery->outcome === EInvoiceSubmitOutcome::TemporaryFailure) {
                $row['result'] = 'ambiguous_recovery';
                $row['failure_reason'] = 'Get-IRN did not settle; GENERATE not attempted';
                if ($pdf) {
                    $row['pdf_regenerated'] = $this->rewritePdf($invoice);
                }

                return $row;
            }
        }

        $eligibility = $this->eligibility->evaluate($invoice);
        $payload = $this->mapper->map($invoice);
        if ($generate && $eligibility->eligible && $payload->isSubmittable()) {
            if (EInvoiceIrnGuard::mustRecoverInsteadOfGenerate($invoice->eInvoiceRecord)
                || EInvoiceIrnGuard::recordHasIssuedIrn($invoice->eInvoiceRecord)) {
                $row['result'] = 'generate_blocked';
                $row['failure_reason'] = 'existing IRN or recover-only state';
            } else {
                $row['generate_attempted'] = true;
                $generated = $this->processor->generateAfterConfirmedAbsent($invoice);
                $invoice = $invoice->fresh(['items', 'eInvoiceRecord']) ?? $invoice;
                $row['result'] = $generated->outcome->value;
                $row['irn'] = $invoice->eInvoiceRecord?->irn;
                $row['ack_no'] = $invoice->eInvoiceRecord?->ack_no;
                $row['final_status'] = $invoice->eInvoiceRecord?->status;
                $row['failure_reason'] = $generated->outcome === EInvoiceSubmitOutcome::Success
                    ? null
                    : ($generated->outcome->value);
            }
        } elseif (! $eligibility->eligible || ! $payload->isSubmittable()) {
            $row['result'] = 'not_generated';
            $row['failure_reason'] = $payload->isSubmittable()
                ? $eligibility->reason
                : 'irp_fields_incomplete:'.implode(',', $payload->gaps);
        }

        if ($pdf) {
            $row['pdf_regenerated'] = $this->rewritePdf($invoice);
        }

        $fresh = $invoice->fresh('eInvoiceRecord');
        $row['final_status'] = $fresh?->eInvoiceRecord?->status ?? $row['final_status'];
        $row['irn'] = $fresh?->eInvoiceRecord?->irn ?? $row['irn'];
        $row['ack_no'] = $fresh?->eInvoiceRecord?->ack_no ?? $row['ack_no'];

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function applyRecovery(array $row, StatutoryInvoice $invoice, EInvoiceSubmitOutcome $outcome): array
    {
        $fresh = $invoice->fresh('eInvoiceRecord') ?? $invoice;
        $row['result'] = match ($outcome) {
            EInvoiceSubmitOutcome::Success => 'recovered',
            EInvoiceSubmitOutcome::IrnNotFound => 'irn_not_found',
            default => $outcome->value,
        };
        $row['irn'] = $fresh->eInvoiceRecord?->irn;
        $row['ack_no'] = $fresh->eInvoiceRecord?->ack_no;
        $row['final_status'] = $fresh->eInvoiceRecord?->status;
        $row['failure_reason'] = EInvoiceIrnGuard::recordHasIssuedIrn($fresh->eInvoiceRecord)
            ? null
            : $outcome->value;

        return $row;
    }

    private function rewritePdf(StatutoryInvoice $invoice): bool
    {
        try {
            $fresh = $invoice->fresh(['items', 'eInvoiceRecord']) ?? $invoice;
            $this->documents->regeneratePresentation($fresh);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
