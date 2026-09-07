<?php

namespace App\Services\HardwareFulfilment\Data;

final class HardwareShipmentReadiness
{
    /**
     * @param  list<string>  $blockers
     * @param  list<string>  $serials
     */
    public function __construct(
        public readonly bool $canCreate,
        public readonly array $blockers,
        public readonly string $status,
        public readonly ?string $pickupBranch,
        public readonly ?string $pickupLocation,
        public readonly ?string $shipTo,
        public readonly ?string $parcel,
        public readonly ?string $invoice,
        public readonly array $serials,
        public readonly ?string $order,
        public readonly ?string $product,
        public readonly bool $alreadyCreated = false,
        public readonly string $provider = 'Shiprocket',
        public readonly string $actionLabel = 'Create Shipment',
    ) {}
}
