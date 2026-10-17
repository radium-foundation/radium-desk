<?php

namespace App\Enums;

enum PosReturnRefundRequestStatus: string
{
    case PendingFinanceReview = 'pending_finance_review';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingFinanceReview => 'Pending Finance review',
            self::Approved => 'Approved for Finance processing',
            self::Rejected => 'Rejected',
        };
    }

    public function isPendingReview(): bool
    {
        return $this === self::PendingFinanceReview;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Approved, self::Rejected], true);
    }
}
