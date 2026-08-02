<?php

namespace App\Services\Platform\Zones;

use App\Enums\PlatformDashboardSection;
use App\Enums\PlatformZoneId;

class AutomationZone extends AbstractWorkspaceLinkZone
{
    protected function zoneId(): PlatformZoneId
    {
        return PlatformZoneId::Automation;
    }

    protected function cardSectionId(): string
    {
        return PlatformDashboardSection::Automation->value;
    }

    protected function permission(): ?string
    {
        return 'automation-operations.view';
    }

    protected function description(): ?string
    {
        return 'Automation health and pipeline workspaces.';
    }
}
