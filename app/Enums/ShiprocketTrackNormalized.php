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
    case OutForPickup = 'out_for_pickup';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Unknown = 'unknown';

    public function dashboardStatusLabel(): string
    {
        return match ($this) {
            self::PickupQueued => 'Pickup queued',
            self::OutForPickup => 'Out for Pickup',
            self::PickedUp => 'Picked Up',
            self::InTransit => 'In Transit',
            self::Delivered => 'Delivered',
            self::Unknown => 'Unknown',
        };
    }

    /**
     * True when provider movement is past Desk "Ready for Pickup".
     */
    public function overridesReadyForPickup(): bool
    {
        return in_array($this, [
            self::OutForPickup,
            self::PickedUp,
            self::InTransit,
            self::Delivered,
        ], true);
    }

    /**
     * Normalized provider-track values that suppress package-photo Needs Action.
     * Mirrors {@see overridesReadyForPickup()} for SQL queue alignment.
     *
     * @return list<string>
     */
    public static function suppressesPackagePhotoNeedsActionValues(): array
    {
        $values = [];
        foreach (self::cases() as $case) {
            if ($case->overridesReadyForPickup()) {
                $values[] = $case->value;
            }
        }

        return $values;
    }

    public function operationalStage(): HardwareFulfilmentOperationalStage
    {
        return match ($this) {
            self::OutForPickup => HardwareFulfilmentOperationalStage::OutForPickup,
            self::PickedUp => HardwareFulfilmentOperationalStage::PickedUp,
            self::Delivered => HardwareFulfilmentOperationalStage::Delivered,
            self::InTransit => HardwareFulfilmentOperationalStage::InTransit,
            default => HardwareFulfilmentOperationalStage::InTransit,
        };
    }
}
