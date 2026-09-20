<?php

namespace App\Services\HardwareFulfilment\Data;

use App\Models\Shipment;

final class HardwareShipmentOrchestrationOutcome
{
    public function __construct(
        public readonly Shipment $shipment,
        public readonly bool $shipmentCreated,
        public readonly bool $awbAssigned,
        public readonly bool $labelGenerated,
        public readonly bool $labelReady,
        public readonly bool $labelRetryRequired,
        public readonly string $message,
    ) {}

    public function flash(): string
    {
        return $this->message;
    }
}
