<?php

namespace App\Enums;

enum OnlineReturnRefundAllocationStatus: string
{
    case PendingFinanceReview = 'pending_finance_review';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingFinanceReview => 'Pending Finance review',
            self::Approved => 'Approved allocation (no payout executed)',
            self::Rejected => 'Rejected',
        };
    }

    public function isPendingReview(): bool
    {
        return $this === self::PendingFinanceReview;
    }
}
