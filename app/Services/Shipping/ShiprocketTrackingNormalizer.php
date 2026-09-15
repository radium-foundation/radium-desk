<?php

namespace App\Services\Shipping;

use App\Enums\ShiprocketTrackNormalized;

/**
 * Maps opaque Shiprocket track strings to a closed Desk set.
 *
 * Verified mappings only:
 * - 12 / pickup-queue phrases: P-07-09-88 Pickup Queue
 * - 19 / Out for Pickup: production GET (P-07-09-290) + official Shiprocket term
 * - 42 / Picked Up: official Shiprocket Postman track example
 * - in_transit text: existing Fake + Desk overlay semantics
 * - delivered text: official Shiprocket term; numeric delivered code is not mapped
 *
 * Everything else stays unknown.
 */
final class ShiprocketTrackingNormalizer
{
    public function normalize(?string $raw): ShiprocketTrackNormalized
    {
        $value = $this->canonical((string) $raw);
        if ($value === '' || $value === 'unknown') {
            return ShiprocketTrackNormalized::Unknown;
        }

        if (in_array($value, ['12', 'pickup queue', 'pickup_requested', 'already in pickup queue', 'pickup_queued'], true)) {
            return ShiprocketTrackNormalized::PickupQueued;
        }

        if (in_array($value, ['19', 'out for pickup', 'out_for_pickup', 'ofp'], true)) {
            return ShiprocketTrackNormalized::OutForPickup;
        }

        if (in_array($value, ['42', 'picked up', 'picked_up'], true)) {
            return ShiprocketTrackNormalized::PickedUp;
        }

        if (in_array($value, ['in_transit', 'in transit'], true)) {
            return ShiprocketTrackNormalized::InTransit;
        }

        if (in_array($value, ['delivered'], true)) {
            return ShiprocketTrackNormalized::Delivered;
        }

        return ShiprocketTrackNormalized::Unknown;
    }

    /**
     * Prefer tracking_data.shipment_status / status. If that value is unknown,
     * use verified current_status / activity text from the same GET payload.
     *
     * @param  list<array<string, mixed>>  $activities
     */
    public function normalizeTrack(?string $raw, array $activities = []): ShiprocketTrackNormalized
    {
        $fromRaw = $this->normalize($raw);
        if ($fromRaw !== ShiprocketTrackNormalized::Unknown) {
            return $fromRaw;
        }

        foreach ($activities as $activity) {
            if (! is_array($activity)) {
                continue;
            }

            foreach (['current_status', 'activity', 'sr-status-label', 'sr_status_label'] as $key) {
                if (! array_key_exists($key, $activity)) {
                    continue;
                }

                $mapped = $this->normalize($this->scalar($activity[$key]));
                if ($mapped !== ShiprocketTrackNormalized::Unknown) {
                    return $mapped;
                }
            }
        }

        return ShiprocketTrackNormalized::Unknown;
    }

    private function canonical(string $raw): string
    {
        $value = strtolower(trim($raw));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value;
    }

    private function scalar(mixed $value): string
    {
        if ($value === null || is_array($value)) {
            return '';
        }

        return trim((string) $value);
    }
}
