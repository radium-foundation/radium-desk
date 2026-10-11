<?php

namespace App\Enums;

/**
 * Workflow state for post-import legacy Cashfree payment reconciliation.
 *
 * Only {@see self::VerifiedPayment} may proceed to payment linking during execute mode.
 * Terminal success for a linked order is {@see self::AlreadyLinked}.
 */
enum LegacyCashfreePaymentReconciliationState: string
{
    case Pending = 'PENDING';
    case Verifying = 'VERIFYING';
    case VerifiedPayment = 'VERIFIED_PAYMENT';
    case AlreadyLinked = 'ALREADY_LINKED';
    case Ambiguous = 'AMBIGUOUS';
    case NoCashfreePayment = 'NO_CASHFREE_PAYMENT';
    case AmountMismatch = 'AMOUNT_MISMATCH';
    case CustomerMismatch = 'CUSTOMER_MISMATCH';
    case ConflictingDeskPayment = 'CONFLICTING_DESK_PAYMENT';
    case RefundStateUnknown = 'REFUND_STATE_UNKNOWN';
    case FailedRetryable = 'FAILED_RETRYABLE';
    case FailedReviewRequired = 'FAILED_REVIEW_REQUIRED';

    public function isRetryable(): bool
    {
        return in_array($this, [self::Pending, self::FailedRetryable, self::RefundStateUnknown], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::AlreadyLinked,
            self::Ambiguous,
            self::NoCashfreePayment,
            self::AmountMismatch,
            self::CustomerMismatch,
            self::ConflictingDeskPayment,
            self::FailedReviewRequired,
        ], true);
    }
}
