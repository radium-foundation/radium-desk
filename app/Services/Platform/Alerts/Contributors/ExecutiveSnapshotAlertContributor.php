<?php

namespace App\Services\Platform\Alerts\Contributors;

use App\Contracts\Platform\PlatformAlertContributor;
use App\Data\Platform\PlatformAlert;
use App\Enums\PlatformAlertSeverity;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;

/**
 * Cache-only alerts from Executive Snapshot zone cache.
 */
class ExecutiveSnapshotAlertContributor implements PlatformAlertContributor
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
        return 'Executive Snapshot';
    }

    public function sortOrder(): int
    {
        return 30;
    }

    public function alerts(): array
    {
        $snapshot = $this->snapshotStore->get('executive_snapshot');

        if ($snapshot === null) {
            return [];
        }

        $severity = PlatformAlertSeverity::fromPlatformHealth($snapshot->status);

        if ($snapshot->stale && $severity === PlatformAlertSeverity::Healthy) {
            $severity = PlatformAlertSeverity::Information;
        }

        if (! in_array($severity, [
            PlatformAlertSeverity::Critical,
            PlatformAlertSeverity::Warning,
            PlatformAlertSeverity::Information,
        ], true)) {
            return [];
        }

        $cardCount = (int) ($snapshot->summary['card_count'] ?? 0);

        return [
            new PlatformAlert(
                id: 'executive_snapshot:status',
                source: $this->key(),
                groupKey: 'executive_snapshot',
                title: 'Executive Snapshot',
                summary: $snapshot->stale
                    ? 'Executive Snapshot is stale — retry pending.'
                    : sprintf('Executive metrics status: %s (%d cards).', $snapshot->statusLabel, $cardCount),
                severity: $severity,
                status: $snapshot->statusLabel,
                lastUpdated: $snapshot->updatedAt,
                count: 1,
                link: route('admin.platform.index').'#platform-zone-executive_snapshot',
            ),
        ];
    }
}
