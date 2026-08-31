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
    ) {}

    /**
     * Interactive agent lookup: Desk callers use this for intake, global-search
     * fallback, and legacy import preview/create.
     *
     * Production default (RDSERVICE_ENABLED=false / empty token) is Admin-only.
     * RDService 429/5xx/timeout still fall through to Admin so agents are not blocked.
     */
    public function fetchInteractive(string $orderId): ?RadiumBoxOrderEnrichment
    {
        $rdFetch = $this->fetchRdService($orderId);

        if ($this->rdServiceUsable($rdFetch)) {
            return $rdFetch->enrichment;
        }

        return $this->radiumBoxClient->fetchOrderEnrichment($orderId);
    }

    /**
     * Background lookup with retry semantics: identity-repair batches use this.
     *
     * RDService 429/5xx/timeout return retriable and do not call Admin on that
     * attempt. Skip / 401 / 404 / malformed / disabled fall through to Admin.
     */
    public function fetchForBackgroundSync(string $orderId): RadiumBoxOrderEnrichmentFetchResult
    {
        $rdFetch = $this->fetchRdService($orderId);

        if ($rdFetch === null) {
            return $this->radiumBoxClient->fetchOrderEnrichmentForBackgroundSync($orderId);
        }

        if ($rdFetch->retriable) {
            return $this->toRadiumBoxFetchResult($rdFetch);
        }

        if ($this->rdServiceUsable($rdFetch)) {
            return $this->toRadiumBoxFetchResult($rdFetch);
        }

        return $this->radiumBoxClient->fetchOrderEnrichmentForBackgroundSync($orderId);
    }

    private function fetchRdService(string $orderId): ?RdServiceFetchResult
    {
        if (! $this->rdServiceClient->isEligible($orderId)) {
            return null;
        }

        return $this->rdServiceClient->fetch($orderId);
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
