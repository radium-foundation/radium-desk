<?php

namespace App\Support;

/**
 * Cashfree merchant order_id namespace for rdservice.in on the shared PG merchant account.
 * Desk persists canonical {@see BusinessOrderId} values (RD{n}); Cashfree may send RDI-RD{n}.
 */
final class CashfreeRdServiceInMerchantOrderId
{
    public const NAMESPACE_PREFIX = 'RDI';

    public const SEPARATOR = '-';

    public const PROVIDER_PREFIX = self::NAMESPACE_PREFIX.self::SEPARATOR.'RD';

    public static function isProviderOrderId(?string $orderId): bool
    {
        if (! is_string($orderId) || trim($orderId) === '') {
            return false;
        }

        $normalized = strtoupper(trim($orderId));

        return preg_match('/^'.preg_quote(self::PROVIDER_PREFIX, '/').'[0-9]{1,61}$/', $normalized) === 1;
    }

    /**
     * Desk business order_id for new webhook-created orders when Cashfree sends RDI-RD{n}.
     */
    public static function deskBusinessOrderIdForWebhookCreate(string $providerOrderId): string
    {
        $trimmed = trim($providerOrderId);
        $normalized = strtoupper($trimmed);
        if (self::isProviderOrderId($normalized)) {
            return self::toCanonicalBusinessOrderId($normalized);
        }

        return $trimmed;
    }

    /**
     * Raw Cashfree merchant order_id for GET /orders/{id} lookups.
     */
    public static function cashfreeMerchantOrderIdForApi(?string $deskBusinessOrderId): ?string
    {
        if (! is_string($deskBusinessOrderId) || trim($deskBusinessOrderId) === '') {
            return null;
        }

        $normalized = strtoupper(trim($deskBusinessOrderId));
        if (self::isProviderOrderId($normalized)) {
            return $normalized;
        }

        if (BusinessOrderId::isRdServiceInService($normalized)) {
            return self::PROVIDER_PREFIX.substr($normalized, 2);
        }

        return $normalized;
    }

    public static function rawProviderOrderId(?string $deskBusinessOrderId): ?string
    {
        if (! is_string($deskBusinessOrderId) || trim($deskBusinessOrderId) === '') {
            return null;
        }

        $normalized = strtoupper(trim($deskBusinessOrderId));
        if (self::isProviderOrderId($normalized)) {
            return $normalized;
        }

        if (BusinessOrderId::isRdServiceInService($normalized)) {
            return self::PROVIDER_PREFIX.substr($normalized, 2);
        }

        return null;
    }

    public static function toCanonicalBusinessOrderId(string $providerOrBusinessOrderId): string
    {
        $normalized = strtoupper(trim($providerOrBusinessOrderId));
        if (self::isProviderOrderId($normalized)) {
            return 'RD'.substr($normalized, strlen(self::PROVIDER_PREFIX));
        }

        return $normalized;
    }
}
