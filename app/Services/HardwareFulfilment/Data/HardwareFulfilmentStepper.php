<?php

namespace App\Services\HardwareFulfilment\Data;

use App\Enums\HardwareFulfilmentOperationalStage;

/**
 * Shared 11-step rail for Operations, show page, and Customer 360.
 * Presentation only — does not mutate fulfilment state.
 */
final class HardwareFulfilmentStepper
{
    /**
     * @var list<string>
     */
    public const STEPS = [
        'Review',
        'Fulfilment',
        'Serial',
        'Invoice',
        'Shipment',
        'AWB',
        'Label',
        'Packing',
        'Pickup',
        'Manifest',
        'Ready',
    ];

    public static function currentIndex(HardwareFulfilmentOperationalRow $row, ?HardwareShipmentReadiness $ready = null): int
    {
        if (! $row->hasFulfilment) {
            return 0;
        }

        if ($row->stage === HardwareFulfilmentOperationalStage::BlockedReview) {
            return 1;
        }

        if ($ready === null) {
            return 1;
        }

        if ($ready->serials === []) {
            return 2;
        }

        if ($ready->invoice === null || $ready->invoice === '') {
            return 3;
        }

        if (! $ready->alreadyCreated) {
            return 4;
        }

        if (! filled($ready->awb)) {
            return 5;
        }

        if ($ready->labelUrl === null) {
            return 6;
        }

        if (! $ready->packageLabelAppliedRecorded) {
            return 7;
        }

        if ($ready->pickupStatus === 'Not requested') {
            return 8;
        }

        if ($ready->manifestStatus === 'Not generated') {
            return 9;
        }

        return 10;
    }

    /**
     * @return list<string>
     */
    public static function milestones(): array
    {
        return self::STEPS;
    }
}
