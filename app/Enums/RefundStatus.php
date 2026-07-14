<?php

namespace App\Enums;

enum RefundStatus: string
{
    case Pending = 'pending';
    case PendingExecution = 'pending_execution';
    case Completed = 'completed';
    case Closed = 'closed';
    case Rejected = 'rejected';
    /** @deprecated Legacy terminal-ish status; prefer Completed/Closed. Kept for backward-compatible rows. */
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending Approval',
            self::PendingExecution => 'Pending Execution',
            self::Completed => 'Completed',
            self::Closed => 'Closed',
            self::Rejected => 'Rejected',
            self::Approved => 'Approved',
        };
    }

    public function isPendingApproval(): bool
    {
        return $this === self::Pending;
    }

    public function isExecutable(): bool
    {
        return $this === self::PendingExecution;
    }

    public function isTerminalSuccess(): bool
    {
        return in_array($this, [self::Completed, self::Closed, self::Approved], true);
    }

    public function countsTowardAlreadyRefunded(): bool
    {
        return in_array($this, [
            self::Pending,
            self::PendingExecution,
            self::Completed,
            self::Closed,
            self::Approved,
        ], true);
    }

    public function allowsCustomerConfirmation(): bool
    {
        return in_array($this, [
            self::Completed,
            self::Closed,
            self::Approved,
        ], true);
    }

    /**
     * @return list<self>
     */
    public static function queueStatuses(): array
    {
        return [
            self::Pending,
            self::PendingExecution,
            self::Completed,
            self::Closed,
            self::Rejected,
            self::Approved,
        ];
    }
}
