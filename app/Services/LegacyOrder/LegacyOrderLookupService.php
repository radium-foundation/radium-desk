<?php

namespace App\Services\LegacyOrder;

use App\Data\LegacyOrderPreview;
use App\Services\RadiumBox\RadiumBoxClient;

class LegacyOrderLookupService
{
    public function __construct(
        private readonly RadiumBoxClient $radiumBoxClient,
    ) {}

    public function lookupLegacyPreview(string $orderId): ?LegacyOrderPreview
    {
        $enrichment = $this->radiumBoxClient->fetchOrderEnrichment($orderId);

        if ($enrichment === null || ! $enrichment->hasLegacyPreviewData()) {
            return null;
        }

        return LegacyOrderPreview::fromEnrichment($orderId, $enrichment);
    }
}
