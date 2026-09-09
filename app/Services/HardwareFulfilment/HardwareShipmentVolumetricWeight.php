<?php

namespace App\Services\HardwareFulfilment;

/**
 * Operator-facing volumetric weight for measured shipment cartons.
 *
 * Desk's Shiprocket create/adhoc mapper sends the packed actual weight as `weight`
 * and does not send a separate volumetric or chargeable field. The provider computes
 * chargeable weight on its side. This helper is display/audit only.
 *
 * No divisor exists in the live mapper or gateway. Shiprocket's published domestic
 * India volumetric formula is L × B × H (cm) / 5000 = kg.
 */
final class HardwareShipmentVolumetricWeight
{
    public const DIVISOR_CM3_PER_KG = 5000;

    public const MIN_DIMENSION_CM = 0.51;

    public const MAX_DIMENSION_CM = 200.0;

    public const MIN_WEIGHT_KG = 0.001;

    public const MAX_WEIGHT_KG = 99.999;

    public static function kilograms(float $lengthCm, float $breadthCm, float $heightCm): float
    {
        return round(($lengthCm * $breadthCm * $heightCm) / self::DIVISOR_CM3_PER_KG, 2);
    }
}
