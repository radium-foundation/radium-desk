<?php

namespace App\Enums;

enum DashboardOemHandoffSessionStatus: string
{
    case OpeningPortal = 'opening_portal';
    case AwaitingVerification = 'awaiting_verification';
    case DraftReady = 'draft_ready';
    case Reviewed = 'reviewed';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
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

    public function displayLabel(): string
    {
        return match ($this) {
            self::OpeningPortal => 'Opening OEM portal',
            self::AwaitingVerification => 'Awaiting OEM verification',
            self::DraftReady => 'Awaiting administrator review',
            self::Reviewed => 'Reviewed (legacy)',
            self::Confirmed => 'Confirmed',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }
}
