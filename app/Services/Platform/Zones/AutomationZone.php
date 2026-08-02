<?php

namespace App\Services\Platform\Zones;

use App\Enums\PlatformZoneId;
use App\Services\Platform\PlatformAutomationOverviewService;

class AutomationZone extends AbstractCachedOverviewZone
{
    public function __construct(
        PlatformZoneSnapshotStore $snapshotStore,
        private readonly PlatformAutomationOverviewService $automation,
    ) {
        parent::__construct($snapshotStore);
    }

    protected function zoneId(): PlatformZoneId
    {
        return PlatformZoneId::Automation;
    }

    protected function permission(): ?string
    {
        return 'automation-operations.view';
    }

    protected function description(): ?string
    {
        return 'Automation health, scheduler, and worker summaries.';
    }

    protected function placeholderMessage(): string
    {
        return 'Automation summaries load after first refresh.';
    }

    protected function overviewPartial(): string
    {
        return 'admin.platform.zones.partials.summary-overview';
    }

    protected function readCachedOverview(): array
    {
        return $this->automation->cachedOverview();
    }

    protected function buildOverview(): array
    {
        return $this->automation->overview(useCache: false);
    }

    protected function diagnosticsFor(string $item): ?array
    {
        if (! $this->automation->isKnownKey($item)) {
            return null;
        }

        return $this->automation->diagnostics($item);
    }
}
