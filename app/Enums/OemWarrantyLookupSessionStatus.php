<?php

namespace App\Enums;

enum OemWarrantyLookupSessionStatus: string
{
    case Pending = 'pending';
    case OpeningPortal = 'opening_portal';
    case AwaitingVerification = 'awaiting_verification';
    case AwaitingResults = 'awaiting_results';
    case DraftReady = 'draft_ready';
    case Consumed = 'consumed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function isActive(): bool
    {
        return match ($this) {
            self::Pending,
            self::OpeningPortal,
            self::AwaitingVerification,
            self::AwaitingResults,
            self::DraftReady => true,
            default => false,
        };
    }
}
