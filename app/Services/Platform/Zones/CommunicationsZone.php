<?php

namespace App\Services\Platform\Zones;

use App\Enums\PlatformDashboardSection;
use App\Enums\PlatformZoneId;

class CommunicationsZone extends AbstractWorkspaceLinkZone
{
    protected function zoneId(): PlatformZoneId
    {
        return PlatformZoneId::Communications;
    }

    protected function cardSectionId(): string
    {
        return PlatformDashboardSection::Communications->value;
    }

    protected function permission(): ?string
    {
        return 'operations-dashboard.view';
    }

    protected function description(): ?string
    {
        return 'Communication health and audit jump-offs.';
    }
}
