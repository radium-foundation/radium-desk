<?php

namespace App\Services\Platform\Zones;

use App\Data\Platform\PlatformCardPayload;
use App\Data\Platform\PlatformZoneSnapshot;
use App\Enums\PlatformHealthStatus;
use App\Enums\PlatformZoneId;
use App\Models\User;
use App\Services\Platform\PlatformCardRegistry;
use App\Services\Platform\Warmers\PlatformWarmingActor;
use App\Support\Platform\OperationsSnapshotPresentation;
use App\Support\Platform\OperationsSnapshotScoring;

/**
 * Wraps existing executive metric cards for async refresh only.
 * First paint uses snapshot/cache — never loads KPI cards eagerly.
 *
 * Presentation title: Operations Snapshot (route key remains executive_snapshot).
 */
class ExecutiveSnapshotZone extends AbstractPlatformZone
{
    public function __construct(
        PlatformZoneSnapshotStore $snapshotStore,
        private readonly PlatformCardRegistry $cardRegistry,
    ) {
        parent::__construct($snapshotStore);
    }

    protected function zoneId(): PlatformZoneId
    {
        return PlatformZoneId::ExecutiveSnapshot;
    }

    protected function description(): ?string
    {
        return OperationsSnapshotPresentation::DESCRIPTION;
    }

    protected function placeholderMessage(): string
    {
        return OperationsSnapshotPresentation::PLACEHOLDER;
    }

    protected function buildFreshSnapshot(User $viewer): PlatformZoneSnapshot
    {
        $cards = [];

        foreach ($this->cardRegistry->all() as $provider) {
            if ($provider->definition()->section !== 'executive') {
                continue;
            }

            if ($provider->definition()->hidden) {
                continue;
            }

            // Warming actor (synthetic or limited) must populate the shared executive snapshot.
            if (! PlatformWarmingActor::isSynthetic($viewer) && ! $provider->authorize($viewer)) {
                continue;
            }

            $cards[] = $provider->refresh($viewer);
        }

        $html = view('admin.platform.zones.partials.card-grid', [
            'cards' => $cards,
            'variant' => 'executive',
        ])->render();

        $status = OperationsSnapshotScoring::aggregateStatus($cards);
        $pressure = OperationsSnapshotScoring::operationalPressurePercent($cards);

        return $this->makeSnapshot(
            status: $status,
            html: $html,
            summary: [
                'state' => 'ready',
                'card_count' => count($cards),
                'operational_pressure_percent' => $pressure,
            ],
            statusLabel: OperationsSnapshotPresentation::statusLabel($status),
        );
    }
}
