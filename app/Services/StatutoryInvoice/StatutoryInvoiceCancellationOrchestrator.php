<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\EInvoiceCancelOutcome;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceCancellation;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\StatutoryInvoice\Data\EInvoiceCancelResult;
use App\Services\StatutoryInvoice\Data\StatutoryCancellationPolicyResult;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceCancellationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        private readonly StatutoryInvoiceLinkedInventoryReversalService $inventoryReversal,
        private readonly StatutoryInvoiceRefundReviewService $refundReview,
        private readonly StatutoryInvoiceService $invoices,
        private readonly AuditLogService $auditLogs,
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
                    'invoice' => 'This statutory invoice was already cancelled or adjusted.',
                ]);
            }

            $invoice = $invoice->fresh(['items', 'inventorySale', 'eInvoiceRecord', 'cancelledBy']) ?? $invoice;

            return StatutoryInvoiceCancellationResult::idempotent(
                invoice: $invoice,
                irnAction: $existingRecord->irn_action ?? [],
                inventoryAction: $existingRecord->inventory_action ?? [],
                creditNoteAction: $existingRecord->credit_note_action ?? [],
                cancellationRecordId: $existingRecord->id,
            );
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

        $ewbAction = $this->ewbCancellation->cancelIfApplicable($invoice, $reason);
        $irnAction = $this->resolveIrnAction($invoice, $reason, $policy);

        return DB::transaction(function () use ($invoice, $actor, $reason, $idempotencyKey, $policy, $irnAction, $ewbAction, $creditNoteAction): StatutoryInvoiceCancellationResult {
            $locked = StatutoryInvoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->load(['items', 'inventorySale', 'eInvoiceRecord', 'cancelledBy']);

            if ($locked->status === StatutoryInvoiceStatus::Cancelled) {
                $record = StatutoryInvoiceCancellation::query()
                    ->where('statutory_invoice_id', $locked->id)
                    ->first();

                return StatutoryInvoiceCancellationResult::idempotent(
                    invoice: $locked,
                    irnAction: $record?->irn_action ?? [],
                    inventoryAction: $record?->inventory_action ?? [],
                    creditNoteAction: $record?->credit_note_action ?? [],
                    cancellationRecordId: $record?->id,
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

            $record = StatutoryInvoiceCancellation::query()->create([
                'statutory_invoice_id' => $locked->id,
                'idempotency_key' => $idempotencyKey,
                'actor_id' => $actor->id,
                'reason' => $reason,
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
                ],
                'completed_at' => now(),
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
                    'cancellation_record_id' => $record->id,
                    'refund_review' => $refundReview->toAuditArray(),
                ],
            );

            return StatutoryInvoiceCancellationResult::completed(
                invoice: $resultInvoice->fresh(['items', 'inventorySale', 'eInvoiceRecord', 'cancelledBy']) ?? $resultInvoice,
                irnAction: $irnAction,
                inventoryAction: $inventoryAction,
                creditNoteAction: $creditNoteAction,
                cancellationRecordId: $record->id,
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveIrnAction(
        StatutoryInvoice $invoice,
        string $reason,
        StatutoryCancellationPolicyResult $policy,
    ): array {
        if (! $policy->requiresIrnCancellation()) {
            return $this->irnActionFromPolicy($policy, [
                'message' => $policy->summary,
            ]);
        }

        $result = $this->irnCancellation->cancelIfRequired($invoice, $reason);
        $action = $this->irnActionFromResult($result);

        if (! $result->succeeded()) {
            throw ValidationException::withMessages([
                'irn' => (string) ($action['message'] ?? 'IRN cancellation failed.'),
            ]);
        }

        return $action;
    }

    /**
     * @param  array<string, mixed>  $extra
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
