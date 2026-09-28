<?php

namespace App\Services\HardwareFulfilment\Data;

use App\Models\Shipment;

final class HardwareCourierReassignmentReconcileOutcome
{
    public function __construct(
        public readonly Shipment $shipment,
        public readonly bool $alreadyAligned,
        public readonly bool $reconciled,
    ) {}

    public function flash(): string
    {
        if ($this->alreadyAligned) {
            return 'Courier and AWB already match Shiprocket. No changes were made.';
        }

        return 'Courier reassignment reconciled from Shiprocket. Regenerate the shipping label before manifest.';
    }
}
