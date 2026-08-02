<?php

namespace App\Services\Platform\Alerts\Contributors;

use App\Contracts\Platform\PlatformAlertContributor;
use App\Data\Platform\PlatformAlert;
use App\Enums\PlatformAlertSeverity;
use App\Enums\PlatformHealthStatus;
use App\Services\Administration\AdministrationSystemHealthSummaryService;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Cache-only alerts from Platform Health overview / zone snapshot.
 */
class PlatformHealthAlertContributor implements PlatformAlertContributor
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

    public function alerts(): array
    {
        $snapshot = $this->snapshotStore->get('platform_health');
        $overview = Cache::get(AdministrationSystemHealthSummaryService::PLATFORM_OVERVIEW_CACHE_KEY);

        $status = null;
        $updatedAt = null;
        $stale = false;

        if ($snapshot !== null) {
            $status = $snapshot->status;
            $updatedAt = $snapshot->updatedAt;
            $stale = $snapshot->stale;
        } elseif (is_array($overview) && isset($overview['status'])) {
            $status = PlatformHealthStatus::tryFrom((string) $overview['status']);
            if (! empty($overview['generated_at']) && is_string($overview['generated_at'])) {
                try {
                    $updatedAt = Carbon::parse($overview['generated_at']);
                } catch (\Throwable) {
                    $updatedAt = null;
                }
            }
        }

        if ($status === null) {
            return [];
        }

        $severity = PlatformAlertSeverity::fromPlatformHealth($status);

        if (! in_array($severity, [PlatformAlertSeverity::Critical, PlatformAlertSeverity::Warning], true) && ! $stale) {
            return [];
        }

        if ($stale && $severity === PlatformAlertSeverity::Healthy) {
            $severity = PlatformAlertSeverity::Information;
        }

        return [
            new PlatformAlert(
                id: 'platform_health:status',
                source: $this->key(),
                groupKey: 'platform_health',
                title: 'Platform Health',
                summary: $stale
                    ? 'Platform Health snapshot is stale — retry pending.'
                    : ($status->label().' infrastructure status.'),
                severity: $severity,
                status: $status->label(),
                lastUpdated: $updatedAt,
                count: 1,
                link: route('admin.platform.index').'#platform-health',
            ),
        ];
    }
}
