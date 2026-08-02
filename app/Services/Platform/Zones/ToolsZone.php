<?php

namespace App\Services\Platform\Zones;

use App\Data\Platform\PlatformZoneSnapshot;
use App\Enums\PlatformDashboardSection;
use App\Enums\PlatformHealthStatus;
use App\Enums\PlatformZoneId;
use App\Models\User;
use App\Services\Platform\PlatformCardRegistry;

/**
 * Aggregates System, Workforce, and Customer Operations deep-links.
 */
class ToolsZone extends AbstractPlatformZone
{
    public function __construct(
        PlatformZoneSnapshotStore $snapshotStore,
        private readonly PlatformCardRegistry $cardRegistry,
    ) {
        parent::__construct($snapshotStore);
    }

    protected function zoneId(): PlatformZoneId
    {
        return PlatformZoneId::Tools;
    }

    protected function description(): ?string
    {
        return 'System settings, workforce, and operator tools.';
    }

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
        $sections = [
            PlatformDashboardSection::System->value,
            PlatformDashboardSection::Workforce->value,
            PlatformDashboardSection::Customers->value,
        ];

        $cards = [];

        foreach ($this->cardRegistry->all() as $provider) {
            if (! in_array($provider->definition()->section, $sections, true)) {
                continue;
            }

            if ($provider->definition()->hidden || ! $provider->authorize($viewer)) {
                continue;
            }

            $cards[] = $provider->load($viewer);
        }

        if ($cards === []) {
            return $this->buildPlaceholderSnapshot($viewer);
        }

        $html = view('admin.platform.zones.partials.card-grid', [
            'cards' => $cards,
            'variant' => 'launchpad',
        ])->render();

        return $this->makeSnapshot(
            status: PlatformHealthStatus::Healthy,
            html: $html,
            summary: [
                'state' => 'links',
                'card_count' => count($cards),
            ],
        );
    }
}
