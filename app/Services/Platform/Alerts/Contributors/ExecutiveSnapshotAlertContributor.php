<?php

namespace App\Services\Platform\Alerts\Contributors;

use App\Contracts\Platform\PlatformAlertContributor;
use App\Data\Platform\PlatformAlert;
use App\Enums\PlatformAlertSeverity;
use App\Support\Platform\OperationsSnapshotPresentation;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;

/**
 * Cache-only alerts from Operations Snapshot zone cache (key: executive_snapshot).
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
        return OperationsSnapshotPresentation::TITLE;
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
        $opsStatus = OperationsSnapshotPresentation::rawStatusLabel($snapshot->status);

        return [
            new PlatformAlert(
                id: 'executive_snapshot:status',
                source: $this->key(),
                groupKey: 'executive_snapshot',
                title: OperationsSnapshotPresentation::TITLE,
                summary: $snapshot->stale
                    ? OperationsSnapshotPresentation::TITLE.' is stale — retry pending.'
                    : OperationsSnapshotPresentation::ALERT_SUMMARY,
                severity: $severity,
                status: OperationsSnapshotPresentation::statusLabel($snapshot->status),
                lastUpdated: $snapshot->updatedAt,
                count: 1,
                link: route('admin.platform.index').'#platform-zone-executive_snapshot',
                related: [
                    [
                        'title' => 'operations_status',
                        'summary' => $opsStatus,
                    ],
                    [
                        'title' => 'affected_kpi_cards',
                        'summary' => (string) $cardCount,
                    ],
                ],
            ),
        ];
    }
}
