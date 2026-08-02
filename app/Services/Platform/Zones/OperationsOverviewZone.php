<?php

namespace App\Services\Platform\Zones;

use App\Enums\PlatformDashboardSection;
use App\Enums\PlatformZoneId;

class OperationsOverviewZone extends AbstractWorkspaceLinkZone
{
    protected function zoneId(): PlatformZoneId
    {
        return PlatformZoneId::OperationsOverview;
    }

    protected function cardSectionId(): string
    {
        return PlatformDashboardSection::Operations->value;
    }

    protected function permission(): ?string
    {
        return 'operations-dashboard.view';
    }

    protected function description(): ?string
    {
        return 'Jump into Operations Control Center surfaces.';
    }
}
