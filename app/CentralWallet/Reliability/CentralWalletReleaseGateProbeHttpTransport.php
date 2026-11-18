<?php

namespace App\CentralWallet\Reliability;

use Illuminate\Http\Client\PendingRequest;

/**
 * HTTP routing for release-gate synthetic Desk probes (read-only GETs).
 *
 * Loopback base URLs require an explicit virtual-host Host header so OpenLiteSpeed routes to Desk.
 */
final class CentralWalletReleaseGateProbeHttpTransport
{
    public static function transportReady(string $baseUrl, string $configuredHostHeader): bool
    {
        if (trim($baseUrl) === '') {
            return false;
        }

        try {
            self::resolveHostHeader($baseUrl, $configuredHostHeader);

            return true;
        } catch (CentralWalletReleaseGateProbeTransportException) {
            return false;
        }
    }

    /**
     * @return non-empty-string|null
     */
    public static function resolveHostHeader(string $baseUrl, string $configuredHostHeader): ?string
    {
        $configuredHostHeader = strtolower(trim($configuredHostHeader));

        if (self::requiresExplicitHostHeader($baseUrl)) {
            if ($configuredHostHeader === '') {
                throw new CentralWalletReleaseGateProbeTransportException(
                    'CENTRAL_WALLET_RELEASE_GATE_PROBE_HOST_HEADER is required when CENTRAL_WALLET_RELEASE_GATE_PROBE_BASE_URL is loopback.',
                );
            }

            self::assertValidHostHeader($configuredHostHeader);

            return $configuredHostHeader;
        }

        if ($configuredHostHeader !== '') {
            self::assertValidHostHeader($configuredHostHeader);

            return $configuredHostHeader;
        }

        return null;
    }

    public static function apply(PendingRequest $request, string $baseUrl, string $configuredHostHeader): PendingRequest
    {
        $hostHeader = self::resolveHostHeader($baseUrl, $configuredHostHeader);
        if ($hostHeader === null) {
            return $request;
        }

        return $request->withHeaders(['Host' => $hostHeader]);
    }

    public static function requiresExplicitHostHeader(string $baseUrl): bool
    {
        return self::isLoopbackHost(self::normalizeHostFromBaseUrl($baseUrl));
    }

    private static function normalizeHostFromBaseUrl(string $baseUrl): string
    {
        $trimmed = trim($baseUrl);
        if ($trimmed === '') {
            return '';
        }

        $parts = parse_url($trimmed);
        if (! is_array($parts)) {
            return '';
        }

        $host = $parts['host'] ?? '';

        return is_string($host) ? strtolower($host) : '';
    }

    private static function isLoopbackHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return true;
        }

        return str_starts_with($host, '127.');
    }

    /**
     * @param  non-empty-string  $hostHeader
     */
    private static function assertValidHostHeader(string $hostHeader): void
    {
        if (str_contains($hostHeader, '/') || str_contains($hostHeader, ':')) {
            throw new CentralWalletReleaseGateProbeTransportException(
                'CENTRAL_WALLET_RELEASE_GATE_PROBE_HOST_HEADER must be a hostname without scheme or path.',
            );
        }

        if (filter_var($hostHeader, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new CentralWalletReleaseGateProbeTransportException(
                'CENTRAL_WALLET_RELEASE_GATE_PROBE_HOST_HEADER is not a valid hostname.',
            );
        }
    }
}
