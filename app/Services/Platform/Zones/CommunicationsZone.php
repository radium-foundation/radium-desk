<?php

namespace App\Services\Platform\Zones;

use App\Enums\PlatformZoneId;
use App\Services\Platform\PlatformCommunicationsOverviewService;

class CommunicationsZone extends AbstractCachedOverviewZone
{
    public function __construct(
        PlatformZoneSnapshotStore $snapshotStore,
        private readonly PlatformCommunicationsOverviewService $communications,
    ) {
        parent::__construct($snapshotStore);
    }

    protected function zoneId(): PlatformZoneId
    {
        return PlatformZoneId::Communications;
    }

    protected function permission(): ?string
    {
        return 'operations-dashboard.view';
    }

    protected function description(): ?string
    {
        return 'Operational communication channel status (diagnostics live in Integration Health).';
    }

    protected function placeholderMessage(): string
    {
        return 'Communications summaries load after Integration Health refresh.';
    }

    protected function overviewPartial(): string
    {
        return 'admin.platform.zones.partials.summary-overview';
    }

    protected function readCachedOverview(): array
    {
        return $this->communications->cachedOverview();
    }

    protected function buildOverview(): array
    {
        return $this->communications->overview(useCache: false);
    }

    protected function diagnosticsFor(string $item): ?array
    {
        if (! $this->communications->isKnownKey($item)) {
            return null;
        }

        return $this->communications->diagnostics($item);
    }
}
