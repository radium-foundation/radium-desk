<?php

namespace App\Services\Platform\Zones;

use App\Data\Platform\PlatformAlert;
use App\Data\Platform\PlatformZoneExpandResult;
use App\Data\Platform\PlatformZoneSnapshot;
use App\Enums\PlatformAlertSeverity;
use App\Enums\PlatformHealthStatus;
use App\Enums\PlatformZoneId;
use App\Models\User;
use App\Services\Platform\Alerts\PlatformAlertAggregator;
use App\Services\Platform\Health\PlatformOverallHealthService;

/**
 * Aggregation-only zone. Reads contributor caches — never live probes.
 */
class CriticalAlertsZone extends AbstractPlatformZone
{
    public function __construct(
        PlatformZoneSnapshotStore $snapshotStore,
        private readonly PlatformAlertAggregator $alerts,
        private readonly PlatformOverallHealthService $overallHealth,
    ) {
        parent::__construct($snapshotStore);
    }

    protected function zoneId(): PlatformZoneId
    {
        return PlatformZoneId::CriticalAlerts;
    }

    protected function expandable(): bool
    {
        return true;
    }

    protected function description(): ?string
    {
        return 'Aggregated alerts from Platform Health, Integration Health, and Executive Snapshot.';
    }

    protected function placeholderMessage(): string
    {
        return 'Critical alerts load from cached zone snapshots after first refresh.';
    }

    public function snapshot(User $viewer): PlatformZoneSnapshot
    {
        $cached = $this->snapshotStore->get($this->definition()->key());

        if ($cached !== null) {
            return $cached;
        }

        // Cache-only aggregation for first paint (no zone HTML warm yet).
        return $this->buildFromCaches(fromCache: true);
    }

    protected function buildFreshSnapshot(User $viewer): PlatformZoneSnapshot
    {
        $snapshot = $this->buildFromCaches(fromCache: false);
        $this->overallHealth->store($this->overallHealth->compute());

        return $snapshot;
    }

    public function expand(User $viewer, string $item): ?PlatformZoneExpandResult
    {
        $all = $this->alerts->collect();
        $match = null;

        foreach ($all as $alert) {
            if ($alert->id === $item || $alert->groupKey === $item) {
                $match = $alert;
                break;
            }
        }

        if ($match === null) {
            return null;
        }

        $html = view('admin.platform.zones.critical-alerts.expand', [
            'alert' => $match,
        ])->render();

        return new PlatformZoneExpandResult(
            zone: $this->definition()->key(),
            item: $item,
            html: $html,
            meta: $match->toArray(),
        );
    }

    private function buildFromCaches(bool $fromCache): PlatformZoneSnapshot
    {
        $collected = $this->alerts->collect();
        $actionable = $this->alerts->actionable($collected);

        $status = $this->statusFromAlerts($actionable);
        $stale = false;
        foreach ($actionable as $alert) {
            if (str_contains(strtolower($alert->summary), 'stale') || str_contains(strtolower($alert->summary), 'retry pending')) {
                $stale = true;
                break;
            }
        }

        $html = view('admin.platform.zones.critical-alerts.overview', [
            'alerts' => $actionable,
            'zoneKey' => $this->definition()->key(),
        ])->render();

        $latest = null;
        foreach ($actionable as $alert) {
            if ($alert->lastUpdated === null) {
                continue;
            }
            if ($latest === null || $alert->lastUpdated->greaterThan($latest)) {
                $latest = $alert->lastUpdated;
            }
        }

        return new PlatformZoneSnapshot(
            key: $this->definition()->key(),
            status: $status,
            statusLabel: $status->label(),
            updatedAt: $latest ?? now(),
            summary: [
                'state' => $actionable === [] ? 'clear' : 'alerts',
                'alert_count' => count($actionable),
                'alerts' => array_map(static fn (PlatformAlert $alert): array => $alert->toArray(), $actionable),
                'stale' => $stale,
                'retry_pending' => $stale,
            ],
            html: $html,
            fromCache: $fromCache,
            available: true,
            stale: $stale,
        );
    }

    /**
     * @param  list<PlatformAlert>  $alerts
     */
    private function statusFromAlerts(array $alerts): PlatformHealthStatus
    {
        $hasCritical = false;
        $hasWarning = false;

        foreach ($alerts as $alert) {
            if ($alert->severity === PlatformAlertSeverity::Critical) {
                $hasCritical = true;
            }
            if (in_array($alert->severity, [PlatformAlertSeverity::Warning, PlatformAlertSeverity::Information], true)) {
                $hasWarning = true;
            }
        }

        return match (true) {
            $hasCritical => PlatformHealthStatus::Critical,
            $hasWarning => PlatformHealthStatus::Warning,
            default => PlatformHealthStatus::Healthy,
        };
    }
}
