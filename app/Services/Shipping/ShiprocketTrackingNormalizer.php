<?php

namespace App\Services\Shipping;

use App\Enums\ShiprocketTrackNormalized;
use App\Services\Shipping\Data\ShiprocketTrackResult;

/**
 * Maps verified Shiprocket track payloads to Desk-normalized movement.
 */
final class ShiprocketTrackingNormalizer
{
    /** @var list<int> Production-verified Shiprocket status ids for Out for Pickup. */
    private const OUT_FOR_PICKUP_STATUS_IDS = [19];

    /**
     * @return array{provider_track_status: string, normalized: ShiprocketTrackNormalized}
     */
    public static function normalize(ShiprocketTrackResult $result): array
    {
        $activity = self::firstActivity($result);
        $activityStatus = trim((string) ($activity['current_status'] ?? $activity['activity'] ?? ''));
        $activityStatusId = isset($activity['current_status_id']) && is_numeric($activity['current_status_id'])
            ? (int) $activity['current_status_id']
            : null;
        $rawStatus = trim($result->status);

        $label = $activityStatus;
        if ($label === '' && $rawStatus !== '' && ! is_numeric($rawStatus)) {
            $label = $rawStatus;
        }
        if ($label === '' && $rawStatus !== '') {
            $label = $rawStatus;
        }

        return [
            'provider_track_status' => $label !== '' ? $label : 'unknown',
            'normalized' => self::normalizeLabel($label, $rawStatus, $activityStatusId),
        ];
    }

    public static function pickupAlreadyAdvanced(ShiprocketTrackResult $result): bool
    {
        return in_array(self::normalize($result)['normalized'], self::advancedPickupStates(), true);
    }

    /**
     * @return list<ShiprocketTrackNormalized>
     */
    public static function advancedPickupStates(): array
    {
        return [
            ShiprocketTrackNormalized::PickupQueued,
            ShiprocketTrackNormalized::OutForPickup,
            ShiprocketTrackNormalized::PickedUp,
            ShiprocketTrackNormalized::InTransit,
            ShiprocketTrackNormalized::Delivered,
        ];
    }

    public static function pickupAdvancedOnShipment(?string $normalizedValue, ?\DateTimeInterface $pickupRequestedAt): bool
    {
        if ($pickupRequestedAt !== null) {
            return true;
        }

        $normalized = ShiprocketTrackNormalized::tryFrom(trim((string) $normalizedValue));

        return $normalized !== null && in_array($normalized, self::advancedPickupStates(), true);
    }

    private static function normalizeLabel(string $label, string $rawStatus, ?int $activityStatusId): ShiprocketTrackNormalized
    {
        if ($activityStatusId !== null && in_array($activityStatusId, self::OUT_FOR_PICKUP_STATUS_IDS, true)) {
            return ShiprocketTrackNormalized::OutForPickup;
        }

        if ($rawStatus !== '' && ctype_digit($rawStatus) && in_array((int) $rawStatus, self::OUT_FOR_PICKUP_STATUS_IDS, true)) {
            return ShiprocketTrackNormalized::OutForPickup;
        }

        $haystack = strtolower(trim($label.' '.$rawStatus));

        return match (true) {
            str_contains($haystack, 'delivered') => ShiprocketTrackNormalized::Delivered,
            str_contains($haystack, 'in transit'), str_contains($haystack, 'in_transit') => ShiprocketTrackNormalized::InTransit,
            str_contains($haystack, 'picked up'), str_contains($haystack, 'picked_up') => ShiprocketTrackNormalized::PickedUp,
            str_contains($haystack, 'out for pickup'), str_contains($haystack, 'out_for_pickup') => ShiprocketTrackNormalized::OutForPickup,
            str_contains($haystack, 'pickup queued'), str_contains($haystack, 'pickup_requested'), str_contains($haystack, 'pickup scheduled') => ShiprocketTrackNormalized::PickupQueued,
            default => ShiprocketTrackNormalized::Unknown,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function firstActivity(ShiprocketTrackResult $result): array
    {
        $first = $result->activities[0] ?? null;

        return is_array($first) ? $first : [];
    }
}
