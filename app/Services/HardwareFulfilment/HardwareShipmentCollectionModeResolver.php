<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareShipmentCollectionMode;
use App\Models\HardwareFulfilment;

/**
 * Hardware shipment collection mode. Current Desk hardware is prepaid.
 * Do not infer COD from Shiprocket courier `cod` capability or from
 * commerce_orders.payment_method (that is the instrument, e.g. UPI).
 */
class HardwareShipmentCollectionModeResolver
{
    public function current(): HardwareShipmentCollectionMode
    {
        return HardwareShipmentCollectionMode::Prepaid;
    }

    public function forFulfilment(HardwareFulfilment $fulfilment): HardwareShipmentCollectionMode
    {
        unset($fulfilment);

        return $this->current();
    }
}
