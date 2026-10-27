<?php

namespace App\Enums;

enum StatutoryInvoiceCancellationOperationStatus: string
{
    case Requested = 'requested';
    case IrnPending = 'irn_pending';
    case IrnReconciliationPending = 'irn_reconciliation_pending';
    case IrnConfirmed = 'irn_confirmed';
    case LocalPending = 'local_pending';
    case Completed = 'completed';
    case FailedRetryable = 'failed_retryable';
    case ManualReviewRequired = 'manual_review_required';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::ManualReviewRequired,
        ], true);
    }

    public function allowsIrnCancelDispatch(): bool
    {
        return in_array($this, [
            self::Requested,
            self::FailedRetryable,
        ], true);
    }

    public function adminLabel(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::IrnPending => 'IRN cancellation in progress',
            self::IrnReconciliationPending => 'IRN reconciliation pending',
            self::IrnConfirmed => 'IRN confirmed',
            self::LocalPending => 'Local finalization pending',
            self::Completed => 'Completed',
            self::FailedRetryable => 'Failed (retryable)',
            self::ManualReviewRequired => 'Manual review required',
        };
    }
}
