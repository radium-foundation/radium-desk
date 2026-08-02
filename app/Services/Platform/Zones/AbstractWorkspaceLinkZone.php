<?php

namespace App\Services\Platform\Zones;

use App\Data\Platform\PlatformZoneSnapshot;
use App\Enums\PlatformHealthStatus;
use App\Enums\PlatformZoneId;
use App\Models\User;
use App\Services\Platform\PlatformCardRegistry;

/**
 * Cheap workspace-link zone backed by existing placeholder cards.
 */
abstract class AbstractWorkspaceLinkZone extends AbstractPlatformZone
{
    public function __construct(
        PlatformZoneSnapshotStore $snapshotStore,
        protected readonly PlatformCardRegistry $cardRegistry,
    ) {
        parent::__construct($snapshotStore);
    }

    abstract protected function cardSectionId(): string;

    /**
     * Workspace links are route-resolution only — safe for first paint.
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
        $cards = [];

        foreach ($this->cardRegistry->all() as $provider) {
            if ($provider->definition()->section !== $this->cardSectionId()) {
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
