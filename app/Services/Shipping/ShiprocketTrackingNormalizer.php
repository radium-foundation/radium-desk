<?php

namespace App\Services\Shipping;

use App\Enums\ShiprocketTrackNormalized;

/**
 * Maps opaque Shiprocket track strings to a closed Desk set.
 * Does not invent statuses: only Fake-gateway strings and P-07-09-88
 * Pickup Queue (numeric 12) are mapped. Everything else is unknown.
 */
final class ShiprocketTrackingNormalizer
{
    public function normalize(?string $raw): ShiprocketTrackNormalized
    {
        $value = strtolower(trim((string) $raw));
        if ($value === '' || $value === 'unknown') {
            return ShiprocketTrackNormalized::Unknown;
        }

        if ($value === '12' || $value === 'pickup queue' || $value === 'pickup_requested'
            || $value === 'already in pickup queue' || $value === 'pickup_queued') {
            return ShiprocketTrackNormalized::PickupQueued;
        }

        if ($value === 'in_transit' || $value === 'in transit') {
            return ShiprocketTrackNormalized::InTransit;
        }

        return ShiprocketTrackNormalized::Unknown;
    }
}
