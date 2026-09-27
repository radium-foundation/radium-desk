<?php

namespace App\Enums;

enum RefundStatutoryAdjustmentStatus: string
{
    case NotApplicable = 'not_applicable';
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case FailedRetryable = 'failed_retryable';
    case FailedManual = 'failed_manual';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::NotApplicable,
            self::Succeeded,
            self::FailedManual,
        ], true);
    }
}
