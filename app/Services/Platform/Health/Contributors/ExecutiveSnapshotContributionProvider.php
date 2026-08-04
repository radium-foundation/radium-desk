<?php

namespace App\Services\Platform\Health\Contributors;

use App\Contracts\Platform\PlatformHealthContributor;
use App\Data\Platform\PlatformHealthContribution;
use App\Enums\PlatformOverallHealthStatus;
use App\Support\Platform\OperationsSnapshotPresentation;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;

class ExecutiveSnapshotContributionProvider implements PlatformHealthContributor
{
    public function __construct(
        private readonly PlatformZoneSnapshotStore $snapshotStore,
    ) {}

    public function key(): string
    {
        return 'executive_snapshot';
    }

    public function label(): string
    {
        return OperationsSnapshotPresentation::TITLE;
    }

    public function sortOrder(): int
    {
        return 30;
    }

    public function contribute(): ?PlatformHealthContribution
    {
        $snapshot = $this->snapshotStore->get('executive_snapshot');

        if ($snapshot === null) {
            return new PlatformHealthContribution(
                source: $this->key(),
                label: $this->label(),
                status: PlatformOverallHealthStatus::Unavailable,
                available: false,
                updatedAt: null,
                stale: false,
                weight: 1,
            );
        }

        return new PlatformHealthContribution(
            source: $this->key(),
            label: $this->label(),
            status: PlatformOverallHealthStatus::fromPlatformHealth($snapshot->status),
            available: $snapshot->available,
            updatedAt: $snapshot->updatedAt,
            stale: $snapshot->stale,
            weight: 1,
        );
    }
}
