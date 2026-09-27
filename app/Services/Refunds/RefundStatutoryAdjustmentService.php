<?php

namespace App\Services\Refunds;

use App\Enums\RefundStatutoryAdjustmentStatus;
use App\Exceptions\Refunds\RefundStatutoryAdjustmentPermanentFailureException;
use App\Exceptions\Refunds\RefundStatutoryAdjustmentRetryableException;
use App\Models\RefundRequest;
use App\Models\RefundStatutoryAdjustment;
use App\Models\StatutoryInvoice;
use App\Services\AutomationIdentityService;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceCancellationResult;
use App\Services\StatutoryInvoice\StatutoryInvoiceCancellationOrchestrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class RefundStatutoryAdjustmentService
{
    public const ADJUSTMENT_IDEMPOTENCY_PREFIX = 'refund-statutory-adjustment:refund:';

    public const ORCHESTRATOR_IDEMPOTENCY_PREFIX = 'refund-statutory-adjustment:invoice:';

    public function __construct(
        private readonly RefundStatutoryAdjustmentEligibility $eligibility,
        private readonly RefundStatutoryAdjustmentOutboxWriter $outboxWriter,
        private readonly StatutoryInvoiceCancellationOrchestrator $orchestrator,
        private readonly AutomationIdentityService $automationIdentity,
    ) {}

    public function enqueueFromRefundCompleted(RefundRequest $refund): ?RefundStatutoryAdjustment
    {
        $refund->loadMissing('order');

        return DB::transaction(function () use ($refund): ?RefundStatutoryAdjustment {
            $existing = RefundStatutoryAdjustment::query()
                ->where('refund_request_id', $refund->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->status->isTerminal()) {
                return $existing;
            }

            $evaluation = $this->eligibility->evaluate($refund);

            if (! $evaluation->eligible) {
                return $this->persistAdjustment(
                    refund: $refund,
                    existing: $existing,
                    status: RefundStatutoryAdjustmentStatus::NotApplicable,
                    invoice: $evaluation->invoice,
                    skipReason: $evaluation->skipReason,
                );
            }

            $invoice = $evaluation->invoice;
            if ($invoice === null) {
                return $this->persistAdjustment(
                    refund: $refund,
                    existing: $existing,
                    status: RefundStatutoryAdjustmentStatus::NotApplicable,
                    skipReason: 'no_linked_statutory_invoice',
                );
            }

            $adjustment = $this->persistAdjustment(
                refund: $refund,
                existing: $existing,
                status: RefundStatutoryAdjustmentStatus::Pending,
                invoice: $invoice,
            );

            $this->outboxWriter->enqueue($adjustment);

            return $adjustment->fresh();
        });
    }

    public function processOutboxEvent(int $refundStatutoryAdjustmentId): void
    {
        $adjustment = RefundStatutoryAdjustment::query()->find($refundStatutoryAdjustmentId);
        if ($adjustment === null) {
            throw new RefundStatutoryAdjustmentPermanentFailureException(
                'Refund statutory adjustment not found: '.$refundStatutoryAdjustmentId,
            );
        }

        if ($adjustment->status === RefundStatutoryAdjustmentStatus::Succeeded) {
            return;
        }

        if ($adjustment->status === RefundStatutoryAdjustmentStatus::NotApplicable) {
            return;
        }

        $adjustment->increment('attempts');

        try {
            $result = DB::transaction(function () use ($adjustment): StatutoryInvoiceCancellationResult {
                $locked = RefundStatutoryAdjustment::query()
                    ->whereKey($adjustment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->status === RefundStatutoryAdjustmentStatus::Succeeded) {
                    return StatutoryInvoiceCancellationResult::idempotent(
                        invoice: StatutoryInvoice::query()->findOrFail($locked->statutory_invoice_id),
                    );
                }

                $refund = $locked->refundRequest()->with('order')->firstOrFail();
                $evaluation = $this->eligibility->evaluate($refund);

                if (! $evaluation->eligible) {
                    $locked->update([
                        'status' => RefundStatutoryAdjustmentStatus::NotApplicable,
                        'skip_reason' => $evaluation->skipReason,
                        'statutory_invoice_id' => $evaluation->invoice?->id ?? $locked->statutory_invoice_id,
                        'processed_at' => now(),
                        'failure_reason' => null,
                    ]);

                    return StatutoryInvoiceCancellationResult::idempotent(
                        invoice: $evaluation->invoice ?? StatutoryInvoice::query()->findOrFail($locked->statutory_invoice_id),
                    );
                }

                $invoice = $evaluation->invoice;
                if ($invoice === null) {
                    throw new RefundStatutoryAdjustmentPermanentFailureException(
                        'Eligible refund adjustment is missing a statutory invoice.',
                    );
                }

                $actor = $this->automationIdentity->systemUser();
                $orchestratorKey = self::orchestratorIdempotencyKey($invoice->id);

                $result = $this->orchestrator->cancel(
                    invoice: $invoice->fresh(['items', 'inventorySale', 'eInvoiceRecord', 'cancelledBy']),
                    actor: $actor,
                    reason: $this->cancellationReason($refund),
                    idempotencyKey: $orchestratorKey,
                );

                $locked->update([
                    'status' => RefundStatutoryAdjustmentStatus::Succeeded,
                    'statutory_invoice_id' => $invoice->id,
                    'orchestrator_result' => $this->serializeOrchestratorResult($result),
                    'processed_at' => now(),
                    'failure_reason' => null,
                    'skip_reason' => null,
                ]);

                return $result;
            });
        } catch (ValidationException $exception) {
            $this->markRetryableFailure($adjustment, $exception->getMessage());

            throw new RefundStatutoryAdjustmentRetryableException($exception->getMessage(), previous: $exception);
        } catch (RefundStatutoryAdjustmentRetryableException|RefundStatutoryAdjustmentPermanentFailureException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->markRetryableFailure($adjustment, $exception->getMessage());

            throw new RefundStatutoryAdjustmentRetryableException($exception->getMessage(), previous: $exception);
        }
    }

    public static function adjustmentIdempotencyKey(int $refundRequestId): string
    {
        return self::ADJUSTMENT_IDEMPOTENCY_PREFIX.$refundRequestId;
    }

    public static function orchestratorIdempotencyKey(int $statutoryInvoiceId): string
    {
        return self::ORCHESTRATOR_IDEMPOTENCY_PREFIX.$statutoryInvoiceId;
    }

    private function persistAdjustment(
        RefundRequest $refund,
        ?RefundStatutoryAdjustment $existing,
        RefundStatutoryAdjustmentStatus $status,
        ?StatutoryInvoice $invoice = null,
        ?string $skipReason = null,
    ): RefundStatutoryAdjustment {
        $values = [
            'refund_request_id' => $refund->id,
            'statutory_invoice_id' => $invoice?->id,
            'status' => $status,
            'idempotency_key' => self::adjustmentIdempotencyKey($refund->id),
            'skip_reason' => $skipReason,
            'processed_at' => $status->isTerminal() ? now() : null,
        ];

        if ($existing !== null) {
            $existing->update($values);

            return $existing->fresh() ?? $existing;
        }

        return RefundStatutoryAdjustment::query()->create($values);
    }

    private function markRetryableFailure(RefundStatutoryAdjustment $adjustment, string $message): void
    {
        $adjustment->update([
            'status' => RefundStatutoryAdjustmentStatus::FailedRetryable,
            'failure_reason' => $message,
        ]);
    }

    private function cancellationReason(RefundRequest $refund): string
    {
        return 'Automatic statutory adjustment after completed refund '.$refund->reference_no.'.';
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOrchestratorResult(StatutoryInvoiceCancellationResult $result): array
    {
        return [
            'idempotent' => $result->idempotent,
            'invoice_status' => $result->invoice->status->value,
            'irn_action' => $result->irnAction,
            'inventory_action' => $result->inventoryAction,
            'credit_note_action' => $result->creditNoteAction,
            'cancellation_record_id' => $result->cancellationRecordId,
        ];
    }
}
