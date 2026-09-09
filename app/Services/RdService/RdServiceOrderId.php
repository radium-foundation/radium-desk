<?php

namespace App\Services\RdService;

use App\Support\BusinessOrderId;

final class RdServiceOrderId
{
    /**
     * RDService.net lookup IDs: historical RD/RA and new RN/RNP.
     * RDE/RDP/RDS/RB are other owners (longest-prefix).
     */
    public static function normalize(?string $orderId): ?string
    {
        if ($orderId === null) {
            return null;
        }

        $trimmed = trim($orderId);

        if ($trimmed === '' || strlen($trimmed) > 64) {
            return null;
        }

        if ($trimmed !== strtoupper($trimmed)) {
            return null;
        }

        $parsed = BusinessOrderId::parse($trimmed);
        if ($parsed === null) {
            return null;
        }

        if (! in_array($parsed['prefix'], ['RD', 'RA', 'RN', 'RNP'], true)) {
            return null;
        }

        return $trimmed;
    }

    public static function isValid(?string $orderId): bool
    {
        return self::normalize($orderId) !== null;
    }
}
