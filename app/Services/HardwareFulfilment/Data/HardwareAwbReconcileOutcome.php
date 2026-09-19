<?php

namespace App\Services\HardwareFulfilment\Data;

use App\Models\Shipment;

final class HardwareAwbReconcileOutcome
{
    public function __construct(
        public readonly Shipment $shipment,
        public readonly bool $alreadyLocal,
        public readonly bool $reconciled,
    ) {}

    public function flash(): string
    {
        if ($this->alreadyLocal) {
            return 'AWB already matches the provider. No changes were made.';
        }

        return 'AWB reconciled from Shiprocket.';
    }
}
