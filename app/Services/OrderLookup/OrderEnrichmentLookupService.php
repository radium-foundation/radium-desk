<?php

namespace App\Services\OrderLookup;

use App\Services\RadiumBox\RadiumBoxClient;
use App\Services\RadiumBox\RadiumBoxOrderEnrichment;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentFetchResult;
use App\Services\RdService\RdServiceClient;
use App\Services\RdService\RdServiceFetchResult;

class OrderEnrichmentLookupService
{
    public function __construct(
        private readonly RdServiceClient $rdServiceClient,
        private readonly RadiumBoxClient $radiumBoxClient,
        private readonly SpokeOrderClientFactory $spokes,
    ) {}

    /**
     * Interactive agent lookup: intake, global-search fallback, legacy import.
     *
     * Production default does not call Old Admin. Spokes are tried in ownership
     * order. Admin is used only when RADIUMBOX_ADMIN_FALLBACK_ENABLED is true.
     */
    public function fetchInteractive(string $orderId): ?RadiumBoxOrderEnrichment
    {
        $spokeFetch = $this->fetchFromSpokes($orderId);

        if ($this->rdServiceUsable($spokeFetch)) {
            return $spokeFetch->enrichment;
        }

        if ($spokeFetch !== null && $spokeFetch->retriable && ! $this->adminFallbackEnabled()) {
            return null;
        }

        if ($this->adminFallbackEnabled()) {
            return $this->radiumBoxClient->fetchOrderEnrichment($orderId);
        }

        return null;
    }

    /**
     * Background lookup with retry semantics.
     *
     * Spoke 429/5xx/timeout are retriable and do not call Admin. Skip / 401 /
     * 404 try the next spoke. Admin is last and only when explicitly enabled.
     */
    public function fetchForBackgroundSync(string $orderId): RadiumBoxOrderEnrichmentFetchResult
    {
        $spokeFetch = $this->fetchFromSpokes($orderId);

        if ($spokeFetch !== null && $spokeFetch->retriable) {
            return $this->toRadiumBoxFetchResult($spokeFetch);
        }

        if ($this->rdServiceUsable($spokeFetch)) {
            return $this->toRadiumBoxFetchResult($spokeFetch);
        }

        if ($this->adminFallbackEnabled()) {
            return $this->radiumBoxClient->fetchOrderEnrichmentForBackgroundSync($orderId);
        }

        if ($spokeFetch !== null) {
            return $this->toRadiumBoxFetchResult($spokeFetch);
        }

        return new RadiumBoxOrderEnrichmentFetchResult(
            retriable: false,
            errorMessage: 'No authoritative order source is configured for this identifier.',
            errorType: 'unsupported_source',
        );
    }

    public function fetchFromSpokes(string $orderId): ?RdServiceFetchResult
    {
        $last = null;

        if ($this->rdServiceClient->isEligible($orderId)) {
            $last = $this->rdServiceClient->fetch($orderId);
            if ($last->retriable || $this->rdServiceUsable($last)) {
                return $last;
            }
        }

        foreach ($this->spokes->configured() as $client) {
            if (! $client->isEligible($orderId)) {
                continue;
            }

            $last = $client->fetch($orderId);
            if ($last->retriable || $this->rdServiceUsable($last)) {
                return $last;
            }
        }

        return $last;
    }

    private function adminFallbackEnabled(): bool
    {
        return (bool) config('order_lookup.admin_fallback_enabled', false)
            || (bool) config('radiumbox.admin_fallback_enabled', false);
    }

    private function rdServiceUsable(?RdServiceFetchResult $fetch): bool
    {
        return $fetch !== null
            && $fetch->succeeded()
            && $fetch->enrichment !== null
            && $fetch->enrichment->hasLegacyPreviewData();
    }

    private function toRadiumBoxFetchResult(RdServiceFetchResult $fetch): RadiumBoxOrderEnrichmentFetchResult
    {
        return new RadiumBoxOrderEnrichmentFetchResult(
            retriable: $fetch->retriable,
            enrichment: $fetch->enrichment,
            errorMessage: $fetch->errorMessage,
            errorType: $fetch->errorType,
            httpStatus: $fetch->httpStatus,
            retryAfterSeconds: $fetch->retryAfterSeconds,
            provider: RdServiceFetchResult::PROVIDER,
        );
    }
}
