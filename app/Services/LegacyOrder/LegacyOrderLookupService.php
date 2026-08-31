<?php

namespace App\Services\LegacyOrder;

use App\Data\LegacyOrderPreview;
use App\Services\OrderLookup\OrderEnrichmentLookupService;

class LegacyOrderLookupService
{
    public function __construct(
        private readonly OrderEnrichmentLookupService $orderEnrichmentLookup,
    ) {}

    public function lookupLegacyPreview(string $orderId): ?LegacyOrderPreview
    {
        $enrichment = $this->orderEnrichmentLookup->fetchInteractive($orderId);

        if ($enrichment === null || ! $enrichment->hasLegacyPreviewData()) {
            return null;
        }

        return LegacyOrderPreview::fromEnrichment($orderId, $enrichment);
    }
}
