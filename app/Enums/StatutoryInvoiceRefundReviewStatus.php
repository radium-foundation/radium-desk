<?php

namespace App\Enums;

enum StatutoryInvoiceRefundReviewStatus: string
{
    case NotApplicable = 'not_applicable';
    case RefundReviewRequired = 'refund_review_required';
    case RefundRequested = 'refund_requested';
    case PendingExecution = 'pending_execution';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::NotApplicable => 'Not applicable',
            self::RefundReviewRequired => 'Refund review required',
            self::RefundRequested => 'Refund requested',
            self::PendingExecution => 'Pending execution',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    public function isActionableForExplicitRefundRequest(): bool
    {
        return in_array($this, [self::RefundReviewRequired, self::Failed], true);
    }
}
