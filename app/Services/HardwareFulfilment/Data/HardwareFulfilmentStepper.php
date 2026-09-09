<?php

namespace App\Services\HardwareFulfilment\Data;

use App\Enums\HardwareFulfilmentOperationalStage;

/**
 * Shared 10-step rail for Operations, show page, and Customer 360.
 * Package photo is parallel evidence — not a blocking step.
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

        if ($ready->pickupStatus === 'Not requested') {
            return 7;
        }

        if ($ready->manifestStatus === 'Not generated') {
            return 8;
        }

        return 9;
    }

    public static function currentCaption(HardwareFulfilmentOperationalRow $row): string
    {
        return match ($row->nextAction) {
            'Review' => 'Review this order before fulfilment can start.',
            'Allocate Serial' => 'Allocate a stock serial to continue.',
            'Issue Invoice' => 'Issue the statutory invoice to continue.',
            'Get Courier Options' => 'Fetch courier options for this shipment.',
            'Enter Package Dimensions' => 'Enter the complete packed shipment dimensions and actual weight.',
            'Create Shipment', 'Reconcile Shipment' => 'Create the shipment after courier selection.',
            'Select Courier' => 'Select a courier to continue.',
            'Assign AWB' => 'Assign the AWB for the selected courier.',
            'Generate Label' => 'Generate the shipping label.',
            'Request Pickup' => 'Request pickup. Package photo is not required.',
            'Generate Manifest' => 'Pickup has been requested.',
            'Upload Package Photo' => 'Evidence can be added after shipment or pickup. It does not block shipping.',
            'Ready' => 'Operational steps are complete.',
            'View' => 'Hardware cannot start yet.',
            default => '',
        };
    }

    /**
     * @return list<string>
     */
    public static function milestones(): array
    {
        return self::STEPS;
    }
}
