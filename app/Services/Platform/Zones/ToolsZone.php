<?php

namespace App\Services\Platform\Zones;

use App\Data\Platform\PlatformZoneSnapshot;
use App\Enums\PlatformHealthStatus;
use App\Enums\PlatformZoneId;
use App\Models\User;
use App\Services\Platform\PlatformToolsCatalogService;

/**
 * Links-only Tools & Diagnostics — no duplicated diagnostic UIs.
 */
class ToolsZone extends AbstractPlatformZone
{
    public function __construct(
        PlatformZoneSnapshotStore $snapshotStore,
        private readonly PlatformToolsCatalogService $catalog,
    ) {
        parent::__construct($snapshotStore);
    }

    protected function zoneId(): PlatformZoneId
    {
        return PlatformZoneId::Tools;
    }

    protected function description(): ?string
    {
        return 'Jump-offs to existing monitoring, audit, and recovery tools.';
    }

    /**
     * Route catalog is cheap — safe for first paint when zone cache is cold.
     */
    public function snapshot(User $viewer): PlatformZoneSnapshot
    {
        $cached = $this->snapshotStore->get($this->definition()->key());

        if ($cached !== null) {
            return $cached;
        }

        return $this->buildFreshSnapshot($viewer);
    }

    protected function buildFreshSnapshot(User $viewer): PlatformZoneSnapshot
    {
        $groups = $this->catalog->groups();

        $html = view('admin.platform.zones.partials.tools-catalog', [
            'groups' => $groups,
            'zoneKey' => $this->definition()->key(),
            'available' => true,
        ])->render();

        return $this->makeSnapshot(
            status: PlatformHealthStatus::Healthy,
            html: $html,
            summary: [
                'state' => 'links',
                'group_count' => count($groups),
            ],
        );
    }
}
