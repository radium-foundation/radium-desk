<?php

namespace App\Enums;

/**
 * Desk-normalized Shiprocket tracking movement.
 * Only values mapped from verified provider strings/codes in
 * ShiprocketTrackingNormalizer. Unknown provider text stays unknown.
 */
enum ShiprocketTrackNormalized: string
{
    case PickupQueued = 'pickup_queued';
    case InTransit = 'in_transit';
    case Unknown = 'unknown';

    public function dashboardStatusLabel(): string
    {
        return match ($this) {
            self::PickupQueued => 'Pickup queued',
            self::InTransit => 'In Transit',
            self::Unknown => 'Unknown',
        };
    }

    /**
     * True when provider movement is past Desk "Ready for Pickup".
     */
    public function overridesReadyForPickup(): bool
    {
        return $this === self::InTransit;
    }
}
