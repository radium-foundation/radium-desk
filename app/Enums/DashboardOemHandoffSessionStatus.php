<?php

namespace App\Enums;

enum DashboardOemHandoffSessionStatus: string
{
    case OpeningPortal = 'opening_portal';
    case AwaitingVerification = 'awaiting_verification';
    case DraftReady = 'draft_ready';
    case Reviewed = 'reviewed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isActive(): bool
    {
        return match ($this) {
            self::OpeningPortal,
            self::AwaitingVerification,
            self::DraftReady => true,
            default => false,
        };
    }
}
