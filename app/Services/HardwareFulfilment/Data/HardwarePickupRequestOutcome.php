<?php

namespace App\Services\HardwareFulfilment\Data;

use App\Models\Shipment;

final class HardwarePickupRequestOutcome
{
    public function __construct(
        public readonly Shipment $shipment,
        public readonly bool $alreadyLocal,
        public readonly bool $reconciled,
        public readonly bool $reconciledProviderAdvanced = false,
    ) {}

    public function flash(): string
    {
        if ($this->reconciledProviderAdvanced) {
            return 'Pickup already advanced at the provider. Local pickup state reconciled.';
        }

        if ($this->reconciled) {
            return 'Pickup already queued at the provider. Local pickup state reconciled.';
        }

        return 'Pickup requested.';
    }
}
