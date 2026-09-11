<?php

namespace App\Support\HardwareFulfilment;

use App\Services\HardwareFulfilment\Data\HardwareShipmentReadiness;

/**
 * Buyer-safe shipment summary from existing readiness fields only.
 */
final class HardwareFulfilmentCustomer360ShipmentPresentation
{
    /**
     * @return array{summary: string, awb: ?string, carrier: ?string, status: ?string}
     */
    public static function present(?HardwareShipmentReadiness $ready): array
    {
        if ($ready === null) {
            return [
                'summary' => 'Not shipped yet',
                'awb' => null,
                'carrier' => null,
                'status' => null,
            ];
        }

        $awb = filled($ready->awb) ? (string) $ready->awb : null;
        $carrier = filled($ready->courier) ? (string) $ready->courier : null;
        $status = trim((string) $ready->status);
        $normalizedStatus = strtolower($status);

        if ($ready->readyForPickup) {
            $summary = 'Ready for pickup';
        } elseif ($awb !== null) {
            $summary = $status !== '' && ! in_array($normalizedStatus, ['none', 'not created', 'not assigned'], true)
                ? $status
                : 'Shipped';
        } elseif ($ready->alreadyCreated) {
            $summary = $status !== '' && ! in_array($normalizedStatus, ['none', 'not created'], true)
                ? $status
                : 'Shipment in progress';
        } elseif (filled($ready->invoice)) {
            $summary = 'Ready for shipment';
        } else {
            $summary = 'Not shipped yet';
        }

        return [
            'summary' => $summary,
            'awb' => $awb,
            'carrier' => $carrier,
            'status' => $status !== '' ? $status : null,
        ];
    }
}
