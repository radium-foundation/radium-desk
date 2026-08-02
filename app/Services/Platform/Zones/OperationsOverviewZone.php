<?php

namespace App\Services\Platform\Zones;

use App\Enums\PlatformZoneId;
use App\Services\Platform\PlatformOperationsOverviewService;

class OperationsOverviewZone extends AbstractCachedOverviewZone
{
    public function __construct(
        PlatformZoneSnapshotStore $snapshotStore,
        private readonly PlatformOperationsOverviewService $operations,
    ) {
        parent::__construct($snapshotStore);
    }

    protected function zoneId(): PlatformZoneId
    {
        return PlatformZoneId::OperationsOverview;
    }

    protected function permission(): ?string
    {
        return 'operations-dashboard.view';
    }

    protected function description(): ?string
    {
        return 'Today’s operational KPI summaries — workflows stay in Operations.';
    }

    protected function placeholderMessage(): string
    {
        return 'Operations summaries load after first refresh.';
    }

    protected function overviewPartial(): string
    {
        return 'admin.platform.zones.partials.summary-overview';
    }

    protected function readCachedOverview(): array
    {
        return $this->operations->cachedOverview();
    }

    protected function buildOverview(): array
    {
        return $this->operations->overview(useCache: false);
    }
}
