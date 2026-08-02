<?php

namespace App\Services\Platform\Zones;

use App\Data\Platform\PlatformZoneSnapshot;
use App\Enums\PlatformHealthStatus;
use App\Enums\PlatformZoneId;
use App\Models\User;
use App\Services\Administration\AdministrationSystemHealthSummaryService;
use App\Services\Platform\Cards\PlatformHealthCardProvider;
use Illuminate\Support\Facades\Cache;

/**
 * First paint: cached overview only. Fresh probes run on refresh().
 */
class PlatformHealthZone extends AbstractPlatformZone
{
    public function __construct(
        PlatformZoneSnapshotStore $snapshotStore,
        private readonly PlatformHealthCardProvider $healthCard,
    ) {
        parent::__construct($snapshotStore);
    }

    protected function zoneId(): PlatformZoneId
    {
        return PlatformZoneId::PlatformHealth;
    }

    protected function description(): ?string
    {
        return 'Scheduler, queue, cache, database, and storage probes.';
    }

    protected function placeholderMessage(): string
    {
        return 'Platform Health loads after first refresh.';
    }

    public function snapshot(User $viewer): PlatformZoneSnapshot
    {
        $cached = $this->snapshotStore->get($this->definition()->key());

        if ($cached !== null) {
            return $cached;
        }

        $overview = Cache::get(AdministrationSystemHealthSummaryService::PLATFORM_OVERVIEW_CACHE_KEY);

        if (is_array($overview) && isset($overview['status'], $overview['status_label'])) {
            $status = PlatformHealthStatus::tryFrom((string) $overview['status'])
                ?? PlatformHealthStatus::Disabled;

            $updatedAt = null;
            if (! empty($overview['generated_at']) && is_string($overview['generated_at'])) {
                try {
                    $updatedAt = \Illuminate\Support\Carbon::parse($overview['generated_at']);
                } catch (\Throwable) {
                    $updatedAt = null;
                }
            }

            $html = view('admin.platform.zones.partials.placeholder', [
                'title' => $this->definition()->title,
                'message' => 'Last known status: '.$overview['status_label'].'. Refreshing…',
                'zoneKey' => $this->definition()->key(),
            ])->render();

            return new PlatformZoneSnapshot(
                key: $this->definition()->key(),
                status: $status,
                statusLabel: (string) $overview['status_label'],
                updatedAt: $updatedAt,
                summary: [
                    'state' => 'overview_cache',
                    'status' => $overview['status'],
                ],
                html: $html,
                fromCache: true,
                available: true,
            );
        }

        return $this->buildPlaceholderSnapshot($viewer);
    }

    protected function buildFreshSnapshot(User $viewer): PlatformZoneSnapshot
    {
        $card = $this->healthCard->refresh($viewer);

        $html = view('admin.platform.zones.partials.card-grid', [
            'cards' => [$card],
            'variant' => 'health',
        ])->render();

        return $this->makeSnapshot(
            status: $card->status,
            html: $html,
            summary: [
                'state' => 'ready',
                'card_key' => $card->key,
            ],
        );
    }
}
