<?php

namespace App\Enums;

enum CashfreeWebhookFailureCategory: string
{
    case DuplicateSucceeded = 'duplicate_succeeded';

    case PaymentExistsInDesk = 'payment_exists_in_desk';

    case InvalidEvent = 'invalid_event';

    case Unresolved = 'unresolved';

    public function label(): string
    {
        return match ($this) {
            self::DuplicateSucceeded => 'Duplicate attempt (sibling succeeded)',
            self::PaymentExistsInDesk => 'Payment already exists in Desk',
            self::InvalidEvent => 'Invalid or non-success event',
            self::Unresolved => 'Unresolved failure',
        };
    }

    public function isActionable(): bool
    {
        return $this === self::Unresolved;
    }

    public function isHistoricalResolved(): bool
    {
        return in_array($this, [self::DuplicateSucceeded, self::PaymentExistsInDesk], true);
    }
}
