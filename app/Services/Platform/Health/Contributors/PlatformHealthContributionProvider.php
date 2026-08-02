<?php

namespace App\Services\Platform\Health\Contributors;

use App\Contracts\Platform\PlatformHealthContributor;
use App\Data\Platform\PlatformHealthContribution;
use App\Enums\PlatformOverallHealthStatus;
use App\Services\Administration\AdministrationSystemHealthSummaryService;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class PlatformHealthContributionProvider implements PlatformHealthContributor
{
    public function __construct(
        private readonly PlatformZoneSnapshotStore $snapshotStore,
    ) {}

    public function key(): string
    {
        return 'platform_health';
    }

    public function label(): string
    {
        return 'Platform Health';
    }

    public function sortOrder(): int
    {
        return 10;
    }

    public function contribute(): ?PlatformHealthContribution
    {
        $snapshot = $this->snapshotStore->get('platform_health');

        if ($snapshot !== null) {
            return new PlatformHealthContribution(
                source: $this->key(),
                label: $this->label(),
                status: PlatformOverallHealthStatus::fromPlatformHealth($snapshot->status),
                available: $snapshot->available,
                updatedAt: $snapshot->updatedAt,
                stale: $snapshot->stale,
                weight: 2,
            );
        }

        $overview = Cache::get(AdministrationSystemHealthSummaryService::PLATFORM_OVERVIEW_CACHE_KEY);

        if (! is_array($overview) || ! isset($overview['status'])) {
            return new PlatformHealthContribution(
                source: $this->key(),
                label: $this->label(),
                status: PlatformOverallHealthStatus::Unavailable,
                available: false,
                updatedAt: null,
                stale: false,
                weight: 2,
            );
        }

        $status = \App\Enums\PlatformHealthStatus::tryFrom((string) $overview['status']);
        $updatedAt = null;
        if (! empty($overview['generated_at']) && is_string($overview['generated_at'])) {
            try {
                $updatedAt = Carbon::parse($overview['generated_at']);
            } catch (\Throwable) {
                $updatedAt = null;
            }
        }

        return new PlatformHealthContribution(
            source: $this->key(),
            label: $this->label(),
            status: $status
                ? PlatformOverallHealthStatus::fromPlatformHealth($status)
                : PlatformOverallHealthStatus::Unavailable,
            available: $status !== null,
            updatedAt: $updatedAt,
            stale: false,
            weight: 2,
        );
    }
}
