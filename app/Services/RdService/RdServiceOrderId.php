<?php

namespace App\Services\RdService;

final class RdServiceOrderId
{
    /**
     * Desk-side eligibility for RDService.net GET /api/integrations/v1/rd-orders/{order_reference}.
     *
     * Historical orders stay RD-prefixed (including Cashfree T-suffix ids).
     * New customer/order ids are RA-prefixed. Hardware RDE/RIN and INQ- are
     * excluded later by Order::isHardwareOrderId / isInquiryOrderId.
     */
    public const PATTERN = '/^(RD|RA)[0-9A-Za-z]{1,61}$/';

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

    /**
     * @return 'RA'|'RD'|null
     */
    public static function namespacePrefix(?string $orderId): ?string
    {
        if ($orderId === null) {
            return null;
        }

        $trimmed = strtoupper(trim($orderId));

        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, 'RA')) {
            return 'RA';
        }

        if (str_starts_with($trimmed, 'RD')) {
            return 'RD';
        }

        return null;
    }

    public static function isRaNamespaced(?string $orderId): bool
    {
        return self::namespacePrefix($orderId) === 'RA';
    }

    public static function isRdNamespaced(?string $orderId): bool
    {
        return self::namespacePrefix($orderId) === 'RD';
    }

    public static function numericCore(?string $orderId): ?string
    {
        if ($orderId === null) {
            return null;
        }

        if (preg_match('/^(?:RD|RA)(\d+)/i', trim($orderId), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    public static function hasTSuffix(?string $orderId): bool
    {
        if ($orderId === null) {
            return false;
        }

        return preg_match('/^(?:RD|RA)\d+T[0-9A-Za-z]+/i', trim($orderId)) === 1;
    }
}
