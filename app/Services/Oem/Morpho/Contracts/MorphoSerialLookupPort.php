<?php

namespace App\Services\Oem\Morpho\Contracts;

use App\Services\Oem\Morpho\Data\MorphoDeviceRegistrationResult;
use App\Services\Oem\Morpho\Data\MorphoOrderExpiryResult;
use App\Services\Oem\Morpho\Data\MorphoSerialValidationResult;

interface MorphoSerialLookupPort
{
    /**
     * OEM device serial validity (format + registry response), not Desk warranty.
     */
    public function validateSerial(string $serial): MorphoSerialValidationResult;

    /**
     * RD registration / whitelist status from OEM status API semantics.
     */
    public function getDeviceRegistrationStatus(string $serial): MorphoDeviceRegistrationResult;

    /**
     * Shopify order / whitelist / expiry fields from OEM intermediate API semantics.
     */
    public function getOrderAndExpiryDetails(string $serial): MorphoOrderExpiryResult;
}
