<?php

namespace App\Services\HardwareFulfilment;

use App\Models\HardwareFulfilment;
use App\Models\Shipment;

/**
 * Desk merchant reference sent to Shiprocket as create/adhoc order_id.
 *
 * New shipments use the fulfilment source_id (for example RBP2281).
 * Historical rows keep HW-{source_id}; search and identity checks support both.
 */
final class HardwareShipmentMerchantReference
{
    public const LEGACY_PREFIX = 'HW-';

    public static function forNewShipment(HardwareFulfilment $fulfilment): string
    {
        $sourceId = trim((string) $fulfilment->source_id);
        if ($sourceId === '') {
            throw new \InvalidArgumentException('Hardware fulfilment source_id is required for a Shiprocket merchant reference.');
        }

        return $sourceId;
    }

    public static function legacyReference(string $sourceId): string
    {
        $sourceId = trim($sourceId);

        return self::LEGACY_PREFIX.$sourceId;
    }

    /**
     * @return list<string>
     */
    public static function searchReferences(Shipment $shipment, HardwareFulfilment $fulfilment): array
    {
        $sourceId = trim((string) $fulfilment->source_id);
        $stored = trim((string) $shipment->shipment_no);
        $legacy = $sourceId !== '' ? self::legacyReference($sourceId) : '';
        $clean = $sourceId;

        $refs = [];
        if ($stored !== '') {
            $refs[] = $stored;
        }
        if ($clean !== '' && ! in_array($clean, $refs, true)) {
            $refs[] = $clean;
        }
        if ($legacy !== '' && ! in_array($legacy, $refs, true)) {
            $refs[] = $legacy;
        }

        return $refs;
    }

    public static function acceptsMerchantOrderId(
        ?string $foundMerchantId,
        Shipment $shipment,
        HardwareFulfilment $fulfilment,
    ): bool {
        $found = trim((string) $foundMerchantId);
        if ($found === '') {
            return false;
        }

        foreach (self::searchReferences($shipment, $fulfilment) as $allowed) {
            if ($found === $allowed) {
                return true;
            }
        }

        return false;
    }
}
