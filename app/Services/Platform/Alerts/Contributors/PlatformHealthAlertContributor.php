<?php

namespace App\Services\Platform\Alerts\Contributors;

use App\Contracts\Platform\PlatformAlertContributor;
use App\Data\Platform\PlatformAlert;
use App\Enums\PlatformAlertSeverity;
use App\Services\Platform\Health\PlatformHealthSnapshotService;

/**
 * Alerts from the shared Platform Health snapshot — never a separate probe or stale zone bake.
 */
class PlatformHealthAlertContributor implements PlatformAlertContributor
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

    public function alerts(): array
    {
        $snapshot = $this->healthSnapshot->current();

        if ($snapshot === null || ! $snapshot->available) {
            return [];
        }

        $status = $snapshot->status;
        $severity = PlatformAlertSeverity::fromPlatformHealth($status);
        $stale = $snapshot->stale;

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
                lastUpdated: $snapshot->generatedAt,
                count: 1,
                link: route('admin.platform.index').'#platform-health',
            ),
        ];
    }
}
