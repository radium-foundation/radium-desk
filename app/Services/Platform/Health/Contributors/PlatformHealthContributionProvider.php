<?php

namespace App\Services\Platform\Health\Contributors;

use App\Contracts\Platform\PlatformHealthContributor;
use App\Data\Platform\PlatformHealthContribution;
use App\Enums\PlatformOverallHealthStatus;
use App\Services\Platform\Health\PlatformHealthSnapshotService;

class PlatformHealthContributionProvider implements PlatformHealthContributor
{
    public function __construct(
        private readonly PlatformHealthSnapshotService $healthSnapshot,
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
        $snapshot = $this->healthSnapshot->current();

        if ($snapshot === null || ! $snapshot->available) {
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

        return new PlatformHealthContribution(
            source: $this->key(),
            label: $this->label(),
            status: PlatformOverallHealthStatus::fromPlatformHealth($snapshot->status),
            available: true,
            updatedAt: $snapshot->generatedAt,
            stale: $snapshot->stale,
            weight: 2,
        );
    }
}
