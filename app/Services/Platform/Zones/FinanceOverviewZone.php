<?php

namespace App\Services\Platform\Zones;

use App\Enums\PlatformDashboardSection;
use App\Enums\PlatformZoneId;

class FinanceOverviewZone extends AbstractWorkspaceLinkZone
{
    protected function zoneId(): PlatformZoneId
    {
        return PlatformZoneId::FinanceOverview;
    }

    protected function cardSectionId(): string
    {
        return PlatformDashboardSection::Finance->value;
    }

    protected function description(): ?string
    {
        return 'Refunds, Cashfree tools, and finance jump-offs.';
    }
}
