<?php

namespace App\Services\Platform\Zones;

use App\Data\Platform\PlatformZoneSnapshot;
use App\Enums\PlatformHealthStatus;
use App\Enums\PlatformZoneId;
use App\Models\User;

class PerformanceZone extends AbstractPlatformZone
{
    protected function zoneId(): PlatformZoneId
    {
        return PlatformZoneId::Performance;
    }

    protected function description(): ?string
    {
        return 'Queue, IVR, and operational performance overview.';
    }

    protected function placeholderMessage(): string
    {
        return 'Performance zone shell is ready for Phase C widgets.';
    }

    protected function buildFreshSnapshot(User $viewer): PlatformZoneSnapshot
    {
        return $this->makeSnapshot(
            status: PlatformHealthStatus::Disabled,
            html: view('admin.platform.zones.partials.placeholder', [
                'title' => $this->definition()->title,
                'message' => $this->placeholderMessage(),
                'zoneKey' => $this->definition()->key(),
            ])->render(),
            summary: ['state' => 'pending'],
        );
    }
}
