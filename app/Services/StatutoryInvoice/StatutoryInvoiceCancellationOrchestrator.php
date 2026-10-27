<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\EInvoiceCancelOutcome;
use App\Enums\StatutoryInvoiceCancellationOperationStatus;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceCancellation;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\StatutoryInvoice\Data\EInvoiceCancelResult;
use App\Services\StatutoryInvoice\Data\IrnCancellationReconciliationResult;
use App\Services\StatutoryInvoice\Data\StatutoryCancellationPolicyResult;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceCancellationResult;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Canonical statutory invoice cancellation entry point for Finance, POS, and
 * controlled fulfilment workflows.
 */
final class StatutoryInvoiceCancellationOrchestrator
{
    public const EVENT_CANCELLED = 'statutory_invoice.cancelled';

    public const EVENT_ADJUSTED = 'statutory_invoice.cancellation_adjusted';

    public const DEFAULT_IDEMPOTENCY_PREFIX = 'statutory-invoice-cancel:';

    public function __construct(
        private readonly StatutoryInvoiceCancellationEligibility $eligibility,
        private readonly StatutoryInvoiceCancellationPolicyService $policy,
        private readonly StatutoryInvoiceCreditNotePolicy $creditNotes,
        private readonly StatutoryInvoiceCreditNoteService $creditNoteService,
        private readonly StatutoryInvoiceEwbCancellationService $ewbCancellation,
        private readonly StatutoryInvoiceIrnCancellationService $irnCancellation,
        private readonly StatutoryInvoiceIrnCancellationReconciliationService $irnReconciliation,
        private readonly StatutoryInvoiceLinkedInventoryReversalService $inventoryReversal,
        private readonly StatutoryInvoiceRefundReviewService $refundReview,
        private readonly StatutoryInvoiceService $invoices,
        private readonly AuditLogService $auditLogs,
        private readonly StatutoryInvoiceCancellationManualReviewAlert $manualReviewAlerts,
        private readonly StatutoryInvoiceCancellationDispatchReservation $dispatchReservation,
    ) {}

    public function cancel(
        StatutoryInvoice $invoice,
        User $actor,
        string $reason,
        string $idempotencyKey,
    ): StatutoryInvoiceCancellationResult {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A cancellation reason is required.',
            ]);
        }

        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            throw ValidationException::withMessages([
                'idempotency_key' => 'An idempotency key is required.',
            ]);
        }

        $existingRecord = StatutoryInvoiceCancellation::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->first();

        if ($existingRecord !== null) {
            if ($existingRecord->idempotency_key !== $idempotencyKey) {
                throw ValidationException::withMessages([
                    'invoice' => 'This statutory invoice already has a cancellation operation in progress or completed.',
                ]);
            }

            if ($existingRecord->requiresManualReview()) {
                throw ValidationException::withMessages([
                    'invoice' => 'This cancellation requires manual review before it can continue.',
                ]);
            }

            if ($existingRecord->isCompleted()) {
                $invoice = $invoice->fresh(['items', 'inventorySale', 'eInvoiceRecord', 'cancelledBy']) ?? $invoice;

                return StatutoryInvoiceCancellationResult::idempotent(
                    invoice: $invoice,
                    irnAction: $existingRecord->irn_action ?? [],
                    inventoryAction: $existingRecord->inventory_action ?? [],
                    creditNoteAction: $existingRecord->credit_note_action ?? [],
                    cancellationRecordId: $existingRecord->id,
                );
            }

            return $this->continueCancellation($existingRecord, $invoice, $actor, $reason);
        }

        $invoice->load(['items', 'inventorySale', 'eInvoiceRecord', 'cancelledBy']);

        if ($invoice->status === StatutoryInvoiceStatus::Cancelled) {
            return StatutoryInvoiceCancellationResult::idempotent(
                invoice: $invoice,
            );
        }

        $this->eligibility->assertCanCancel($invoice);

        $policy = $this->policy->evaluate($invoice);
        $creditNoteAction = $this->creditNotes->actionForCancellation($invoice);

        if ($policy->requiresCreditNote() && ! $this->creditNotes->canMint()) {
            throw ValidationException::withMessages([
                'credit_note' => 'A credit note is required for this cancellation but credit-note minting is not enabled.',
            ]);
        }

        try {
            $operation = StatutoryInvoiceCancellation::query()->create([
                'statutory_invoice_id' => $invoice->id,
                'idempotency_key' => $idempotencyKey,
                'actor_id' => $actor->id,
                'reason' => $reason,
                'status' => StatutoryInvoiceCancellationOperationStatus::Requested,
                'correlation_id' => (string) Str::uuid(),
                'attempt_count' => 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            $existingRecord = StatutoryInvoiceCancellation::query()
                ->where('statutory_invoice_id', $invoice->id)
                ->firstOrFail();

            if ($existingRecord->idempotency_key !== $idempotencyKey) {
                throw ValidationException::withMessages([
                    'invoice' => 'This statutory invoice already has a cancellation operation in progress or completed.',
                ]);
            }

            return $this->continueCancellation($existingRecord, $invoice, $actor, $reason, $policy, $creditNoteAction);
        }

        return $this->continueCancellation($operation, $invoice, $actor, $reason, $policy, $creditNoteAction);
    }

    public function reconcileInFlightCancellation(StatutoryInvoiceCancellation $operation): StatutoryInvoiceCancellationResult
    {
        $operation = DB::transaction(function () use ($operation): StatutoryInvoiceCancellation {
            return StatutoryInvoiceCancellation::query()
                ->whereKey($operation->id)
                ->lockForUpdate()
                ->firstOrFail();
        });

        if ($operation->requiresManualReview()) {
            throw ValidationException::withMessages([
                'invoice' => 'This cancellation requires manual review before it can continue.',
            ]);
        }

        if ($operation->isCompleted()) {
            $invoice = $operation->invoice?->fresh(['items', 'inventorySale', 'eInvoiceRecord', 'cancelledBy'])
                ?? StatutoryInvoice::query()->findOrFail($operation->statutory_invoice_id);

            return StatutoryInvoiceCancellationResult::idempotent(
                invoice: $invoice,
                irnAction: $operation->irn_action ?? [],
                inventoryAction: $operation->inventory_action ?? [],
                creditNoteAction: $operation->credit_note_action ?? [],
                cancellationRecordId: $operation->id,
            );
        }

        $actor = $operation->actor ?? User::query()->findOrFail($operation->actor_id);
        $invoice = StatutoryInvoice::query()->findOrFail($operation->statutory_invoice_id);

        return $this->continueCancellation($operation, $invoice, $actor, (string) $operation->reason);
    }

    private function continueCancellation(
        StatutoryInvoiceCancellation $operation,
        StatutoryInvoice $invoice,
        User $actor,
        string $reason,
        ?StatutoryCancellationPolicyResult $policy = null,
        ?array $creditNoteAction = null,
    ): StatutoryInvoiceCancellationResult {
        $invoice = $invoice->fresh(['items', 'inventorySale', 'eInvoiceRecord', 'cancelledBy']) ?? $invoice;

        if ($invoice->status === StatutoryInvoiceStatus::Cancelled && $operation->isCompleted()) {
            return StatutoryInvoiceCancellationResult::idempotent(
                invoice: $invoice,
                irnAction: $operation->irn_action ?? [],
                inventoryAction: $operation->inventory_action ?? [],
                creditNoteAction: $operation->credit_note_action ?? [],
                cancellationRecordId: $operation->id,
            );
        }

        $this->eligibility->assertCanCancel($invoice);

        $policy ??= $this->policy->evaluate($invoice);
        $creditNoteAction ??= $this->creditNotes->actionForCancellation($invoice);

        $ewbAction = $this->ewbCancellation->cancelIfApplicable($invoice, $reason);

        $irnAction = $operation->irn_action ?? [];
        if (! $this->irnPhaseComplete($operation, $policy)) {
            $irnAction = $this->executeIrnPhase($operation, $invoice, $reason, $policy);
        } elseif ($irnAction === []) {
            $irnAction = $this->irnActionFromPolicy($policy, [
                'message' => $policy->summary,
            ]);
        }

        try {
            return $this->finalizeLocally(
                operation: $operation,
                invoice: $invoice,
                actor: $actor,
                reason: $reason,
                policy: $policy,
                irnAction: $irnAction,
                ewbAction: $ewbAction,
                creditNoteAction: $creditNoteAction,
            );
        } catch (Throwable $exception) {
            $operation->refresh();
            if ($operation->status === StatutoryInvoiceCancellationOperationStatus::IrnConfirmed
                || $operation->status === StatutoryInvoiceCancellationOperationStatus::LocalPending) {
                $operation->update([
                    'status' => StatutoryInvoiceCancellationOperationStatus::ManualReviewRequired,
                    'last_error' => $exception->getMessage(),
                ]);
                $this->manualReviewAlerts->emit($operation, 'local_finalization_failed', [
                    'exception_class' => $exception::class,
                ]);
            }

            throw $exception;
        }
    }

    private function irnPhaseComplete(
        StatutoryInvoiceCancellation $operation,
        StatutoryCancellationPolicyResult $policy,
    ): bool {
        if (! $policy->requiresIrnCancellation()) {
            return true;
        }

        return in_array($operation->status, [
            StatutoryInvoiceCancellationOperationStatus::IrnConfirmed,
            StatutoryInvoiceCancellationOperationStatus::LocalPending,
            StatutoryInvoiceCancellationOperationStatus::Completed,
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function executeIrnPhase(
        StatutoryInvoiceCancellation $operation,
        StatutoryInvoice $invoice,
        string $reason,
        StatutoryCancellationPolicyResult $policy,
    ): array {
        if ($operation->status === StatutoryInvoiceCancellationOperationStatus::IrnReconciliationPending) {
            return $this->reconcileUnknownIrnOutcome($operation, $invoice, $policy);
        }

        if ($operation->status === StatutoryInvoiceCancellationOperationStatus::IrnPending) {
            return $this->recoverStrandedIrnPending($operation, $invoice, $policy);
        }

        if ($operation->status === StatutoryInvoiceCancellationOperationStatus::FailedRetryable) {
            $reconciledEarly = $this->reconcileBeforeFailedRetryableDispatch($operation, $invoice, $policy);
            if ($reconciledEarly !== null) {
                return $reconciledEarly;
            }
            $operation->refresh();
        }

        if (! $operation->status->allowsIrnCancelDispatch()) {
            throw ValidationException::withMessages([
                'irn' => 'Cancellation is not in a state that allows IRN dispatch.',
            ]);
        }

        $operation = $this->dispatchReservation->reserve($operation->id, $this->maxIrnCancelDispatchAttempts());

        $result = $this->irnCancellation->cancelIfRequired($invoice, $reason);
        $action = $this->irnActionFromResult($result);

        $operation->update([
            'irn_action' => $action,
            'provider_reference' => $result->correlationId,
        ]);

        if ($result->outcome === EInvoiceCancelOutcome::Unknown) {
            $operation->update([
                'status' => StatutoryInvoiceCancellationOperationStatus::IrnReconciliationPending,
                'last_error' => (string) ($action['message'] ?? 'unknown'),
            ]);

            throw ValidationException::withMessages([
                'irn' => 'IRN cancellation outcome is ambiguous. Reconcile provider status before retrying.',
            ]);
        }

        if ($result->outcome === EInvoiceCancelOutcome::TemporaryFailure) {
            $operation->update([
                'status' => StatutoryInvoiceCancellationOperationStatus::FailedRetryable,
                'last_error' => (string) ($action['message'] ?? 'temporary_failure'),
            ]);

            throw ValidationException::withMessages([
                'irn' => (string) ($action['message'] ?? 'IRN cancellation failed temporarily.'),
            ]);
        }

        if (! $result->succeeded()) {
            $operation->update([
                'status' => StatutoryInvoiceCancellationOperationStatus::ManualReviewRequired,
                'last_error' => (string) ($action['message'] ?? 'permanent_failure'),
            ]);
            $this->manualReviewAlerts->emit($operation, 'irn_cancel_permanent_failure');

            throw ValidationException::withMessages([
                'irn' => (string) ($action['message'] ?? 'IRN cancellation failed.'),
            ]);
        }

        $operation->update([
            'status' => StatutoryInvoiceCancellationOperationStatus::IrnConfirmed,
            'irn_confirmed_at' => now(),
        ]);

        return $action;
    }

    /**
     * Get-IRN before any new CANCEL from failed_retryable (explicit transient failure, not unknown).
     *
     * @return array<string, mixed>|null Return action when IRN already cancelled; null to proceed to reservation.
     */
    private function reconcileBeforeFailedRetryableDispatch(
        StatutoryInvoiceCancellation $operation,
        StatutoryInvoice $invoice,
        StatutoryCancellationPolicyResult $policy,
    ): ?array {
        $reconciled = $this->irnReconciliation->reconcile($invoice);

        if ($reconciled->isCancelled()) {
            return $this->markIrnConfirmedFromReconciliation($operation, $invoice, $reconciled);
        }

        if (! $reconciled->isActive()) {
            $operation->update([
                'status' => StatutoryInvoiceCancellationOperationStatus::IrnReconciliationPending,
                'last_error' => 'Provider IRN status is still unknown; another live CANCEL is not permitted.',
            ]);

            throw ValidationException::withMessages([
                'irn' => 'IRN cancellation outcome is still ambiguous. Reconcile provider status before retrying.',
            ]);
        }

        if (! $policy->requiresIrnCancellation()) {
            $this->markManualReview($operation, 'irn_active_but_policy_no_longer_requires_cancel');

            throw ValidationException::withMessages([
                'irn' => 'IRN is still active but cancellation is no longer eligible under policy.',
            ]);
        }

        return null;
    }

    /**
     * Recover after dispatch reservation (irn_pending) without issuing another blind CANCEL.
     *
     * @return array<string, mixed>
     */
    private function recoverStrandedIrnPending(
        StatutoryInvoiceCancellation $operation,
        StatutoryInvoice $invoice,
        StatutoryCancellationPolicyResult $policy,
    ): array {
        $reconciled = $this->irnReconciliation->reconcile($invoice);

        if ($reconciled->isCancelled()) {
            return $this->markIrnConfirmedFromReconciliation($operation, $invoice, $reconciled);
        }

        if ($reconciled->isActive()) {
            if (! $policy->requiresIrnCancellation()) {
                $this->markManualReview($operation, 'irn_active_but_policy_no_longer_requires_cancel');

                throw ValidationException::withMessages([
                    'irn' => 'IRN is still active but cancellation is no longer eligible under policy.',
                ]);
            }

            $operation->update([
                'status' => StatutoryInvoiceCancellationOperationStatus::FailedRetryable,
                'last_error' => 'Interrupted IRN cancel attempt; provider still shows an active IRN. '
                    .'Recovery used Get-IRN only — use Finance Continue to retry after review.',
            ]);

            throw ValidationException::withMessages([
                'irn' => 'Interrupted cancellation: IRN is still active at the provider. No automatic CANCEL was sent during recovery.',
            ]);
        }

        $operation->update([
            'status' => StatutoryInvoiceCancellationOperationStatus::IrnReconciliationPending,
            'last_error' => 'Interrupted IRN cancel attempt; provider outcome remains unknown.',
        ]);

        throw ValidationException::withMessages([
            'irn' => 'Interrupted cancellation: provider IRN status could not be confirmed. Manual review or further reconciliation is required.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function reconcileUnknownIrnOutcome(
        StatutoryInvoiceCancellation $operation,
        StatutoryInvoice $invoice,
        StatutoryCancellationPolicyResult $policy,
    ): array {
        $reconciled = $this->irnReconciliation->reconcile($invoice);

        if ($reconciled->isCancelled()) {
            return $this->markIrnConfirmedFromReconciliation($operation, $invoice, $reconciled);
        }

        if ($reconciled->isActive()) {
            if (! $policy->requiresIrnCancellation()) {
                $this->markManualReview($operation, 'irn_active_but_policy_no_longer_requires_cancel');

                throw ValidationException::withMessages([
                    'irn' => 'IRN is still active but cancellation is no longer eligible under policy.',
                ]);
            }

            $operation->update([
                'status' => StatutoryInvoiceCancellationOperationStatus::FailedRetryable,
                'last_error' => null,
            ]);

            return $this->executeIrnPhase($operation, $invoice, (string) $operation->reason, $policy);
        }

        $this->markManualReview($operation, 'irn_reconciliation_unknown');

        throw ValidationException::withMessages([
            'irn' => 'IRN cancellation could not be reconciled with the provider. Manual review is required.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function markIrnConfirmedFromReconciliation(
        StatutoryInvoiceCancellation $operation,
        StatutoryInvoice $invoice,
        IrnCancellationReconciliationResult $reconciled,
    ): array {
        $cancelResult = $reconciled->cancelResult;
        if ($cancelResult !== null && $cancelResult->succeeded()) {
            $this->irnCancellation->recordProviderCancellationEvidence($cancelResult, $invoice);
        }

        $action = $cancelResult !== null
            ? $this->irnActionFromResult($cancelResult)
            : $this->irnActionFromResult(EInvoiceCancelResult::success('reconciled', $invoice->eInvoiceRecord?->irn));

        $operation->update([
            'status' => StatutoryInvoiceCancellationOperationStatus::IrnConfirmed,
            'irn_confirmed_at' => now(),
            'irn_action' => array_merge($action, ['reconciled' => true]),
        ]);

        return $operation->irn_action ?? $action;
    }

    private function markManualReview(StatutoryInvoiceCancellation $operation, string $reason): void
    {
        $operation->update([
            'status' => StatutoryInvoiceCancellationOperationStatus::ManualReviewRequired,
        ]);
        $this->manualReviewAlerts->emit($operation, $reason);
    }

    private function maxIrnCancelDispatchAttempts(): int
    {
        return max(1, (int) config('statutory_invoices.einvoice.irn_cancellation_max_dispatch_attempts', 3));
    }

    private function finalizeLocally(
        StatutoryInvoiceCancellation $operation,
        StatutoryInvoice $invoice,
        User $actor,
        string $reason,
        StatutoryCancellationPolicyResult $policy,
        array $irnAction,
        array $ewbAction,
        array $creditNoteAction,
    ): StatutoryInvoiceCancellationResult {
        $operation->update([
            'status' => StatutoryInvoiceCancellationOperationStatus::LocalPending,
        ]);

        return DB::transaction(function () use ($operation, $invoice, $actor, $reason, $policy, $irnAction, $ewbAction, $creditNoteAction): StatutoryInvoiceCancellationResult {
            $locked = StatutoryInvoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->load(['items', 'inventorySale', 'eInvoiceRecord', 'cancelledBy']);

            if ($locked->status === StatutoryInvoiceStatus::Cancelled) {
                $operation->refresh();
                if (! $operation->isCompleted()) {
                    $operation->update([
                        'status' => StatutoryInvoiceCancellationOperationStatus::Completed,
                        'completed_at' => now(),
                    ]);
                }

                return StatutoryInvoiceCancellationResult::idempotent(
                    invoice: $locked,
                    irnAction: $operation->irn_action ?? $irnAction,
                    inventoryAction: $operation->inventory_action ?? [],
                    creditNoteAction: $operation->credit_note_action ?? [],
                    cancellationRecordId: $operation->id,
                );
            }

            $this->eligibility->assertCanCancel($locked);

            $inventoryAction = $this->inventoryReversal->reverseIfRequired($locked, $actor, $reason);
            $previousStatus = $locked->status->value;
            $refundReview = null;

            if ($policy->requiresCreditNote()) {
                $creditNoteAction = $this->creditNoteService->issueForCancellation(
                    original: $locked,
                    actor: $actor,
                    reason: $reason,
                    idempotencyKey: $this->creditNoteService->idempotencyKeyFor($locked),
                );
                $resultInvoice = $locked->fresh(['items', 'inventorySale', 'eInvoiceRecord', 'cancelledBy']) ?? $locked;
                $refundReview = $this->refundReview->snapshot($resultInvoice);
                $event = self::EVENT_ADJUSTED;
                $finalStatus = $resultInvoice->status->value;
            } else {
                $resultInvoice = $this->invoices->cancel($locked, $actor, $reason);
                $refundReview = $this->refundReview->snapshot($resultInvoice);
                $event = self::EVENT_CANCELLED;
                $finalStatus = $resultInvoice->status->value;
            }

            $operation->update([
                'irn_action' => $irnAction,
                'inventory_action' => $inventoryAction,
                'credit_note_action' => $creditNoteAction,
                'result_summary' => [
                    'invoice_number' => $locked->invoice_number,
                    'previous_status' => $previousStatus,
                    'final_status' => $finalStatus,
                    'workflow' => $policy->workflow->value,
                    'ewb_action' => $ewbAction,
                    'refund_review' => $refundReview->toAuditArray(),
                    'correlation_id' => $operation->correlation_id,
                ],
                'status' => StatutoryInvoiceCancellationOperationStatus::Completed,
                'completed_at' => now(),
                'last_error' => null,
            ]);

            $this->auditLogs->log(
                userId: $actor->id,
                event: $event,
                auditable: $resultInvoice,
                oldValues: [
                    'status' => $previousStatus,
                ],
                newValues: [
                    'status' => $finalStatus,
                    'cancel_reason' => $reason,
                    'workflow' => $policy->workflow->value,
                    'ewb_action' => $ewbAction,
                    'irn_action' => $irnAction,
                    'inventory_action' => $inventoryAction,
                    'credit_note_action' => $creditNoteAction,
                    'cancellation_record_id' => $operation->id,
                    'cancellation_correlation_id' => $operation->correlation_id,
                    'refund_review' => $refundReview->toAuditArray(),
                ],
            );

            return StatutoryInvoiceCancellationResult::completed(
                invoice: $resultInvoice->fresh(['items', 'inventorySale', 'eInvoiceRecord', 'cancelledBy']) ?? $resultInvoice,
                irnAction: $irnAction,
                inventoryAction: $inventoryAction,
                creditNoteAction: $creditNoteAction,
                cancellationRecordId: $operation->id,
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function irnActionFromPolicy(StatutoryCancellationPolicyResult $policy, array $extra = []): array
    {
        return array_merge([
            'status' => $policy->irnDecision->value,
            'workflow' => $policy->workflow->value,
            'irn_age_hours' => $policy->irnAgeHours,
            'has_submitted_irn' => $policy->hasSubmittedIrn,
            'is_b2b' => $policy->isB2b,
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function irnActionFromResult(EInvoiceCancelResult $result): array
    {
        $message = match ($result->outcome) {
            EInvoiceCancelOutcome::NotRequired => 'No IRN cancellation was required.',
            EInvoiceCancelOutcome::AlreadyCancelled => 'IRN was already cancelled.',
            EInvoiceCancelOutcome::Success => 'IRN cancellation completed.',
            EInvoiceCancelOutcome::ProviderNotImplemented => 'IRN cancellation is not implemented for the configured e-invoice provider.',
            EInvoiceCancelOutcome::TemporaryFailure => 'IRN cancellation failed temporarily. Retry later without duplicating local cancellation.',
            EInvoiceCancelOutcome::PermanentFailure => 'IRN cancellation failed permanently.',
            EInvoiceCancelOutcome::Unknown => 'IRN cancellation result is ambiguous and requires manual review.',
        };

        return [
            'status' => $result->outcome->value,
            'provider' => $result->provider,
            'irn' => $result->irn,
            'message' => $message,
            'payload' => $result->payload,
            'correlation_id' => $result->correlationId,
        ];
    }
}
