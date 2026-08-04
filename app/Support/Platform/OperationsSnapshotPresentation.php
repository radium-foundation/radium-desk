<?php

namespace App\Support\Platform;

use App\Enums\PlatformHealthStatus;

/**
 * Presentation labels for the Operations Snapshot zone (formerly “Executive Snapshot”).
 * Does not change thresholds, calculations, routes, or APIs.
 */
final class OperationsSnapshotPresentation
{
    public const TITLE = 'Operations Snapshot';

    public const DESCRIPTION = 'Live operational KPIs for cases, workload and business throughput.';

    public const PLACEHOLDER = 'Operations Snapshot loads after first refresh.';

    public const TOOLTIP = 'Operations Snapshot measures business workload. Platform Health measures infrastructure health.';

    public const ALERT_SUMMARY = 'Operational KPI status';

    public static function statusLabel(PlatformHealthStatus $status): string
    {
        return match ($status) {
            PlatformHealthStatus::Critical => 'Operations Critical',
            PlatformHealthStatus::Warning => 'Operations Warning',
            PlatformHealthStatus::Healthy => 'Operations Healthy',
            PlatformHealthStatus::Disabled => 'Operations Unavailable',
        };
    }

    public static function rawStatusLabel(PlatformHealthStatus $status): string
    {
        return match ($status) {
            PlatformHealthStatus::Critical => 'Critical',
            PlatformHealthStatus::Warning => 'Warning',
            PlatformHealthStatus::Healthy => 'Healthy',
            PlatformHealthStatus::Disabled => 'Unavailable',
        };
    }
}
