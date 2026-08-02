<?php

namespace App\Services\Platform\Zones;

use App\Data\Platform\PlatformCardPayload;
use App\Data\Platform\PlatformZoneSnapshot;
use App\Enums\PlatformHealthStatus;
use App\Enums\PlatformZoneId;
use App\Models\User;
use App\Services\Platform\PlatformCardRegistry;

/**
 * Wraps existing executive metric cards for async refresh only.
 * First paint uses snapshot/cache — never loads KPI cards eagerly.
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
        return 'Live executive KPIs for cases, queue, and throughput.';
    }

    protected function placeholderMessage(): string
    {
        return 'Executive metrics load after first refresh.';
    }

    protected function buildFreshSnapshot(User $viewer): PlatformZoneSnapshot
    {
        $cards = [];

        foreach ($this->cardRegistry->all() as $provider) {
            if ($provider->definition()->section !== 'executive') {
                continue;
            }

            if ($provider->definition()->hidden || ! $provider->authorize($viewer)) {
                continue;
            }

            $cards[] = $provider->refresh($viewer);
        }

        $html = view('admin.platform.zones.partials.card-grid', [
            'cards' => $cards,
            'variant' => 'executive',
        ])->render();

        $status = $this->worstStatus($cards);

        return $this->makeSnapshot(
            status: $status,
            html: $html,
            summary: [
                'state' => 'ready',
                'card_count' => count($cards),
            ],
        );
    }

    /**
     * @param  list<PlatformCardPayload>  $cards
     */
    private function worstStatus(array $cards): PlatformHealthStatus
    {
        if ($cards === []) {
            return PlatformHealthStatus::Disabled;
        }

        return PlatformHealthStatus::worst(
            ...array_map(
                static fn (PlatformCardPayload $card): PlatformHealthStatus => $card->status,
                $cards,
            ),
        );
    }
}
