<?php

namespace App\Services\Platform\Zones;

use App\Enums\PlatformZoneId;
use App\Services\Platform\PlatformFinanceOverviewService;

class FinanceOverviewZone extends AbstractCachedOverviewZone
{
    public function __construct(
        PlatformZoneSnapshotStore $snapshotStore,
        private readonly PlatformFinanceOverviewService $finance,
    ) {
        parent::__construct($snapshotStore);
    }

    protected function zoneId(): PlatformZoneId
    {
        return PlatformZoneId::FinanceOverview;
    }

    protected function description(): ?string
    {
        return 'Refund queue and Cashfree payment summaries.';
    }

    protected function placeholderMessage(): string
    {
        return 'Finance summaries load after first refresh.';
    }

    protected function overviewPartial(): string
    {
        return 'admin.platform.zones.partials.summary-overview';
    }

    protected function readCachedOverview(): array
    {
        return $this->finance->cachedOverview();
    }

    protected function buildOverview(): array
    {
        return $this->finance->overview(useCache: false);
    }
}
