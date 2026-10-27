<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceCancellationOperationStatus;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\StatutoryCancellationPolicyResult;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceCancellationAdminContext;
use App\Support\Finance\FinanceAccess;

final class StatutoryInvoiceCancellationAdminPresentation
{
    public const FINANCE_CA_PROCEDURE = 'Stop using this screen for further automatic attempts. Contact Finance or your CA with the correlation reference, provider portal evidence, and this invoice number. Get-IRN code 2154 / IRN-not-found alone does not prove the IRN was cancelled.';

    public function __construct(
        private readonly StatutoryInvoiceCancellationEligibility $eligibility,
        private readonly StatutoryInvoiceCancellationPolicyService $policy,
        private readonly StatutoryInvoiceCreditNotePolicy $creditNotes,
    ) {}

    public function forInvoice(StatutoryInvoice $invoice, ?\App\Models\User $viewer): StatutoryInvoiceCancellationAdminContext
    {
        $invoice->loadMissing(['eInvoiceRecord', 'cancellation']);
        $operation = $invoice->cancellation;
        $authorized = FinanceAccess::allowsInvoiceCancel($viewer);

        $policy = $this->policy->evaluate($invoice);
        $consequences = $this->buildConsequences($invoice, $policy);

        $irnStatusSummary = $this->irnStatusSummary($invoice);
        $windowSummary = $this->windowSummary($policy);

        $requiresEscalation = $operation?->requiresManualReview() ?? false;
        $invoiceIssued = $invoice->status === StatutoryInvoiceStatus::Issued;

        $maySubmit = $authorized
            && $invoiceIssued
            && ! $requiresEscalation
            && $this->eligibilityCanAttempt($invoice);

        $showForm = $authorized && ($invoiceIssued || $operation !== null) && ! $requiresEscalation;

        $status = $operation?->status;
        [$statusLabel, $statusHelp] = $this->operationStatusCopy($status, $policy);

        return new StatutoryInvoiceCancellationAdminContext(
            maySubmitCancellationRequest: $maySubmit,
            showCancellationForm: $showForm && $invoiceIssued,
            showPolicyPanel: $authorized && $invoiceIssued,
            requiresFinanceCaEscalation: $requiresEscalation,
            policySummary: $policy->summary,
            irnStatusSummary: $irnStatusSummary,
            windowSummary: $windowSummary,
            operation: $operation,
            operationStatus: $status,
            operationStatusLabel: $statusLabel,
            operationStatusHelp: $statusHelp,
            correlationId: $operation?->correlation_id,
            lastError: $operation?->last_error,
            consequences: $consequences,
            financeCaProcedure: self::FINANCE_CA_PROCEDURE,
        );
    }

    private function eligibilityCanAttempt(StatutoryInvoice $invoice): bool
    {
        try {
            $this->eligibility->assertCanCancel($invoice);

            return true;
        } catch (\Illuminate\Validation\ValidationException) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function buildConsequences(StatutoryInvoice $invoice, StatutoryCancellationPolicyResult $policy): array
    {
        $lines = [];

        if ($this->eligibility->irnCancellationRequired($invoice)) {
            $lines[] = 'A submitted IRN must be cancelled at the invoice portal before the statutory invoice can be cancelled locally.';
        }

        if ($policy->requiresCreditNote()) {
            $lines[] = $this->creditNotes->requirementSummary();
            $lines[] = 'Beyond the statutory IRN cancellation window, the original invoice typically stays active; a credit note may be issued instead of cancelling the original invoice.';
        }

        if ($this->eligibility->inventoryReversalApplicable($invoice)) {
            $lines[] = 'Linked POS inventory and the posted POS finance journal may be reversed.';
        }

        $lines[] = 'Customer payment is not refunded automatically. Finance must review and create a separate refund request when applicable.';
        $lines[] = 'Submitting this form starts a durable cancellation operation. IRN cancellation at the provider is confirmed only after the operation reports IRN confirmed or completed — not when the request is accepted.';

        return $lines;
    }

    private function irnStatusSummary(StatutoryInvoice $invoice): string
    {
        $record = $invoice->eInvoiceRecord;
        if ($record === null) {
            return 'No e-invoice record.';
        }

        if ($record->hasIssuedIrn()) {
            $payload = is_array($record->response_payload) ? $record->response_payload : [];
            if (($payload['irn_cancelled'] ?? false) === true) {
                return 'IRN marked cancelled in local records.';
            }

            return 'Submitted IRN on file (provider status must be confirmed through the cancellation operation).';
        }

        return 'E-invoice status: '.$record->status.' (no issued IRN).';
    }

    private function windowSummary(StatutoryCancellationPolicyResult $policy): string
    {
        if ($policy->irnAgeHours !== null) {
            return sprintf('IRN age ~%s hours; policy: %s', $policy->irnAgeHours, $policy->summary);
        }

        return $policy->summary;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function operationStatusCopy(?StatutoryInvoiceCancellationOperationStatus $status, StatutoryCancellationPolicyResult $policy): array
    {
        if ($status === null) {
            return ['Not started', 'No cancellation operation exists for this invoice yet.'];
        }

        return match ($status) {
            StatutoryInvoiceCancellationOperationStatus::Requested => [
                'In progress',
                'Cancellation requested; waiting for provider IRN cancellation or local finalization.',
            ],
            StatutoryInvoiceCancellationOperationStatus::IrnPending => [
                'Dispatch reserved',
                'A cancel dispatch slot was reserved. If this persists, run desk:statutory-cancellation-reconcile (Get-IRN only) — do not assume the IRN is cancelled.',
            ],
            StatutoryInvoiceCancellationOperationStatus::IrnReconciliationPending => [
                'Reconciliation required',
                'Provider IRN cancel outcome is unknown. Do not submit another blind cancel — use Continue below after Finance review or run desk:statutory-cancellation-reconcile. Code 2154 alone does not prove cancellation.',
            ],
            StatutoryInvoiceCancellationOperationStatus::IrnConfirmed,
            StatutoryInvoiceCancellationOperationStatus::LocalPending => [
                'IRN confirmed — finalizing',
                'IRN cancellation is confirmed at the provider; local invoice finalization is pending or incomplete.',
            ],
            StatutoryInvoiceCancellationOperationStatus::Completed => [
                'Completed',
                'Cancellation operation completed. Verify invoice status and credit-note outcome below.',
            ],
            StatutoryInvoiceCancellationOperationStatus::FailedRetryable => [
                'Retryable failure',
                'Transient IRN failure. You may retry with Continue after reviewing provider status.',
            ],
            StatutoryInvoiceCancellationOperationStatus::ManualReviewRequired => [
                'Manual review required',
                self::FINANCE_CA_PROCEDURE,
            ],
        };
    }
}
