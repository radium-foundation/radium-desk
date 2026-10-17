<?php

namespace App\Enums;

enum OnlineReturnRefundAllocationLineExecutionStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Held = 'held';
    case ManualPending = 'manual_pending';

    public function label(): string
    {
        return match ($this) {
            self::Succeeded => 'Executed',
            self::Failed => 'Failed',
            self::ManualPending => 'Pending manual evidence',
            self::Held => 'Held (unsupported)',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Held], true);
    }
}
