<?php

namespace App\Infrastructure\IntegrationHealth\Probes;

use App\Infrastructure\IntegrationHealth\Contracts\IntegrationHealthProbe;
use App\Infrastructure\IntegrationHealth\IntegrationHealthSnapshot;

/**
 * Placeholder probe for integrations not yet wired.
 *
 * Keeps the registry shape stable for future WhatsApp, Email, Shipping, and AI probes.
 */
class PlaceholderIntegrationHealthProbe implements IntegrationHealthProbe
{
    public function __construct(
        private readonly string $integrationKey,
        private readonly string $integrationLabel,
    ) {}

    public function key(): string
    {
        return $this->integrationKey;
    }

    public function label(): string
    {
        return $this->integrationLabel;
    }

    public function probe(): IntegrationHealthSnapshot
    {
        return new IntegrationHealthSnapshot(
            key: $this->key(),
            label: $this->label(),
            connectionStatus: 'not_configured',
            lastSuccessAt: null,
            lastFailureAt: null,
            lastSyncAt: null,
            retryCount: 0,
            averageResponseTimeMs: null,
            lastErrorMessage: 'Probe not yet implemented.',
        );
    }
}
