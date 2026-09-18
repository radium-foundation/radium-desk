<?php

namespace App\Services\Shipping;

use App\Services\Shipping\Data\ShiprocketPickupResult;

/**
 * Shiprocket generate/pickup rejected because provider shipment status
 * no longer permits pickup generation (e.g. already Out for Pickup).
 */
final class ShiprocketPickupInvalidStatus
{
    public const PHRASE = 'Invalid Status for pickup generation';

    public static function matchesRejectedResult(ShiprocketPickupResult $result): bool
    {
        if ($result->retryable || $result->isAccepted()) {
            return false;
        }

        return self::containsPhrase((string) $result->error);
    }

    public static function containsPhrase(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }

        return str_contains(strtolower($text), strtolower(self::PHRASE));
    }
}
