<?php

namespace App\Services\RdService;

final class RdServiceOrderId
{
    /**
     * Matches RDService.net GET /api/integrations/v1/rd-orders/{rdorderid}.
     */
    public const PATTERN = '/^RD[0-9A-Za-z]{1,61}$/';

    public static function normalize(?string $orderId): ?string
    {
        if ($orderId === null) {
            return null;
        }

        $trimmed = trim($orderId);

        if ($trimmed === '' || strlen($trimmed) > 64) {
            return null;
        }

        if (preg_match(self::PATTERN, $trimmed) !== 1) {
            return null;
        }

        return $trimmed;
    }

    public static function isValid(?string $orderId): bool
    {
        return self::normalize($orderId) !== null;
    }
}
