<?php

namespace App\Services\Platform\Zones;

use App\Enums\PlatformZoneId;
use App\Services\Platform\PlatformPerformanceOverviewService;

class PerformanceZone extends AbstractCachedOverviewZone
{
    public function __construct(
        PlatformZoneSnapshotStore $snapshotStore,
        private readonly PlatformPerformanceOverviewService $performance,
    ) {
        parent::__construct($snapshotStore);
    }

    protected function zoneId(): PlatformZoneId
    {
        return PlatformZoneId::Performance;
    }

    protected function description(): ?string
    {
        return 'Queue, notifications, automation throughput, and IVR summaries.';
    }

    protected function placeholderMessage(): string
    {
        return 'Performance summaries load after first refresh.';
    }

    protected function overviewPartial(): string
    {
        return 'admin.platform.zones.partials.summary-overview';
    }

    protected function readCachedOverview(): array
    {
        return $this->performance->cachedOverview();
    }

    protected function buildOverview(): array
    {
        return $this->performance->overview(useCache: false);
    }

    protected function diagnosticsFor(string $item): ?array
    {
        if (! $this->performance->isKnownKey($item)) {
            return null;
        }

        return $this->performance->diagnostics($item);
    }
}
