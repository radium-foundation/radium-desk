<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\EInvoiceCancelOutcome;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceCancellation;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\StatutoryInvoice\Data\EInvoiceCancelResult;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceCancellationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Canonical Finance/Admin statutory invoice cancellation entry point.
 */
final class StatutoryInvoiceCancellationOrchestrator
{
    public const EVENT_CANCELLED = 'statutory_invoice.cancelled';

    public const DEFAULT_IDEMPOTENCY_PREFIX = 'statutory-invoice-cancel:';

    public function __construct(
        private readonly StatutoryInvoiceCancellationEligibility $eligibility,
        private readonly StatutoryInvoiceCreditNotePolicy $creditNotes,
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
                    'invoice' => 'This statutory invoice was already cancelled.',
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

        $creditNoteAction = $this->creditNotes->actionForCancellation($invoice);
        if (($creditNoteAction['status'] ?? '') === 'blocked_requirements_not_met') {
            throw ValidationException::withMessages([
                'credit_note' => (string) ($creditNoteAction['message'] ?? 'Credit note requirements are not met.'),
            ]);
        }

        $irnAction = $this->resolveIrnAction($invoice, $reason);

        return DB::transaction(function () use ($invoice, $actor, $reason, $idempotencyKey, $irnAction, $creditNoteAction): StatutoryInvoiceCancellationResult {
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
            $cancelled = $this->invoices->cancel($locked, $actor, $reason);
            $refundReview = $this->refundReview->snapshot($cancelled);

            $record = StatutoryInvoiceCancellation::query()->create([
                'statutory_invoice_id' => $cancelled->id,
                'idempotency_key' => $idempotencyKey,
                'actor_id' => $actor->id,
                'reason' => $reason,
                'irn_action' => $irnAction,
                'inventory_action' => $inventoryAction,
                'credit_note_action' => $creditNoteAction,
                'result_summary' => [
                    'invoice_number' => $cancelled->invoice_number,
                    'previous_status' => $previousStatus,
                    'final_status' => $cancelled->status->value,
                    'refund_review' => $refundReview->toAuditArray(),
                ],
                'completed_at' => now(),
            ]);

            $this->auditLogs->log(
                userId: $actor->id,
                event: self::EVENT_CANCELLED,
                auditable: $cancelled,
                oldValues: [
                    'status' => $previousStatus,
                ],
                newValues: [
                    'status' => $cancelled->status->value,
                    'cancel_reason' => $reason,
                    'irn_action' => $irnAction,
                    'inventory_action' => $inventoryAction,
                    'credit_note_action' => $creditNoteAction,
                    'cancellation_record_id' => $record->id,
                    'refund_review' => $refundReview->toAuditArray(),
                ],
            );

            return StatutoryInvoiceCancellationResult::completed(
                invoice: $cancelled->fresh(['items', 'inventorySale', 'eInvoiceRecord', 'cancelledBy']) ?? $cancelled,
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
    private function resolveIrnAction(StatutoryInvoice $invoice, string $reason): array
    {
        if (! $this->eligibility->irnCancellationRequired($invoice)) {
            return [
                'status' => 'not_required',
                'message' => 'No submitted IRN requires cancellation.',
            ];
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
