<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceCancellationOperationStatus;
use App\Models\StatutoryInvoiceCancellation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Atomically reserves one live IRN CANCEL dispatch slot on the cancellation row.
 * The transaction commits before any provider HTTP call (see orchestrator).
 */
final class StatutoryInvoiceCancellationDispatchReservation
{
    public function __construct(
        private readonly StatutoryInvoiceCancellationManualReviewAlert $manualReviewAlerts,
    ) {}

    public function reserve(int $operationId, int $maxAttempts): StatutoryInvoiceCancellation
    {
        $maxAttempts = max(1, $maxAttempts);

        $limitReached = false;

        $operation = DB::transaction(function () use ($operationId, $maxAttempts, &$limitReached): StatutoryInvoiceCancellation {
            $operation = StatutoryInvoiceCancellation::query()
                ->whereKey($operationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($operation->requiresManualReview()) {
                throw ValidationException::withMessages([
                    'invoice' => 'This cancellation requires manual review before it can continue.',
                ]);
            }

            if ($operation->attempt_count >= $maxAttempts) {
                $operation->update([
                    'status' => StatutoryInvoiceCancellationOperationStatus::ManualReviewRequired,
                    'last_error' => "IRN cancellation dispatch limit ({$maxAttempts}) reached.",
                ]);
                $limitReached = true;

                return $operation->fresh() ?? $operation;
            }

            $operation->update([
                'status' => StatutoryInvoiceCancellationOperationStatus::IrnPending,
                'attempt_count' => $operation->attempt_count + 1,
            ]);

            return $operation->fresh() ?? $operation;
        });

        if ($limitReached) {
            $this->manualReviewAlerts->emit($operation, 'irn_cancel_max_dispatch_attempts_exceeded');

            throw ValidationException::withMessages([
                'irn' => "IRN cancellation dispatch limit ({$maxAttempts}) reached. Manual review is required.",
            ]);
        }

        return $operation;
    }
}
