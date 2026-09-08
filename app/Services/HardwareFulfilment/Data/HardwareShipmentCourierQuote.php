<?php

namespace App\Services\HardwareFulfilment\Data;

use App\Models\HardwareFulfilment;
use App\Services\Shipping\Data\ShiprocketCourierOption;

final class HardwareShipmentCourierQuote
{
    /**
     * @param  array{
     *     pickup: string,
     *     shipping: array<string, string>,
     *     parcel: array{weight: float, length: float, breadth: float, height: float},
     *     parcel_source?: string
     * }  $ready
     */
    public static function fingerprint(
        array $ready,
        string $pickupPostcode,
        int $cod = 0,
        ?string $providerOrderId = null,
    ): string {
        $parcel = $ready['parcel'];
        $shipping = $ready['shipping'];

        return hash('sha256', json_encode([
            'pickup' => $ready['pickup'],
            'pickup_postcode' => $pickupPostcode,
            'delivery_postcode' => $shipping['pincode'] ?? '',
            'country' => $shipping['country'] ?? '',
            'weight' => $parcel['weight'] ?? null,
            'length' => $parcel['length'] ?? null,
            'breadth' => $parcel['breadth'] ?? null,
            'height' => $parcel['height'] ?? null,
            'parcel_source' => $ready['parcel_source'] ?? null,
            'cod' => $cod,
            'provider_order_id' => $providerOrderId,
        ], JSON_THROW_ON_ERROR));
    }

    public static function ttlSeconds(): int
    {
        return max(60, (int) config('shipping.courier_options_ttl_seconds', 900));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function options(HardwareFulfilment $fulfilment): array
    {
        $snapshot = $fulfilment->courier_options_snapshot;
        if (! is_array($snapshot)) {
            return [];
        }

        $rows = $snapshot['options'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $option = ShiprocketCourierOption::fromArray($row);
            if ($option !== null) {
                $out[] = $option->toArray();
            }
        }

        return $out;
    }

    public static function recommendationReturned(HardwareFulfilment $fulfilment): bool
    {
        $snapshot = $fulfilment->courier_options_snapshot;

        return is_array($snapshot) && (bool) ($snapshot['recommendation_returned'] ?? false);
    }

    public static function recommendedCourierId(HardwareFulfilment $fulfilment): ?string
    {
        $snapshot = $fulfilment->courier_options_snapshot;
        if (! is_array($snapshot)) {
            return null;
        }

        $id = trim((string) ($snapshot['recommended_courier_id'] ?? ''));

        return $id === '' ? null : $id;
    }

    public static function isFresh(HardwareFulfilment $fulfilment, string $expectedFingerprint): bool
    {
        if (trim((string) $fulfilment->courier_options_fingerprint) !== $expectedFingerprint) {
            return false;
        }

        if ($fulfilment->courier_options_expires_at === null) {
            return false;
        }

        return $fulfilment->courier_options_expires_at->isFuture();
    }

    public static function selectedIdIsAvailable(HardwareFulfilment $fulfilment): bool
    {
        $selected = trim((string) $fulfilment->selected_courier_id);
        if ($selected === '') {
            return false;
        }

        foreach (self::options($fulfilment) as $option) {
            if (($option['courier_id'] ?? null) === $selected) {
                return true;
            }
        }

        return false;
    }

    public static function hasValidSelection(HardwareFulfilment $fulfilment, string $expectedFingerprint): bool
    {
        return self::isFresh($fulfilment, $expectedFingerprint)
            && self::selectedIdIsAvailable($fulfilment);
    }
}
