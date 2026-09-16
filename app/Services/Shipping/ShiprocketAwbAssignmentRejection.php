<?php

namespace App\Services\Shipping;

use App\Services\Shipping\Data\ShiprocketAwbResult;

/**
 * Classifies Shiprocket AWB assignment outcomes for recovery eligibility.
 *
 * VERIFIED: HttpShiprocketGateway never parses awb_code from HTTP 4xx responses;
 * assignAwb returns status=rejected with awb=null. Production RDP21 remained
 * awb=null after HTTP 400 "Given courier not serviceable".
 */
final class ShiprocketAwbAssignmentRejection
{
    public static function isCourierNotServiceable(?string $error): bool
    {
        if ($error === null || trim($error) === '') {
            return false;
        }

        return str_contains(strtolower($error), 'given courier not serviceable');
    }

    public static function isDefinitiveNoAwbAssignment(ShiprocketAwbResult $result): bool
    {
        if ($result->retryable || $result->status === 'assigned' || filled($result->awb)) {
            return false;
        }

        return self::isCourierNotServiceable($result->error);
    }
}
