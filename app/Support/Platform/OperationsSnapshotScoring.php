<?php

namespace App\Support\Platform;

use App\Data\Platform\PlatformCardPayload;
use App\Enums\PlatformHealthStatus;

/**
 * Weighted operational pressure score for Operations Snapshot zone status.
 *
 * Uses the same per-card credit model as PlatformOverallHealthService::scorePercent
 * (Healthy = full weight, Warning = half, Critical/Unavailable = zero). Zone
 * severity is derived from operational pressure (100 − health%), not worst-of-cards.
 */
final class OperationsSnapshotScoring
{
    /** Operational pressure below this is Healthy (0% inclusive). */
    public const HEALTHY_BELOW_PERCENT = 15.0;

    /** Operational pressure at or above this is Critical. */
    public const CRITICAL_AT_OR_ABOVE_PERCENT = 30.0;

    /**
     * @param  list<PlatformCardPayload>  $cards
     */
    public static function aggregateStatus(array $cards): PlatformHealthStatus
    {
        if ($cards === []) {
            return PlatformHealthStatus::Disabled;
        }

        $pressure = self::operationalPressurePercent($cards);

        if ($pressure === null) {
            return PlatformHealthStatus::Disabled;
        }

        return self::statusFromOperationalPressure($pressure);
    }

    /**
     * Map operational pressure to zone severity.
     *
     * Healthy: 0% – <15%
     * Warning: 15% – <30%
     * Critical: 30%+
     */
    public static function statusFromOperationalPressure(float $pressurePercent): PlatformHealthStatus
    {
        if ($pressurePercent >= self::CRITICAL_AT_OR_ABOVE_PERCENT) {
            return PlatformHealthStatus::Critical;
        }

        if ($pressurePercent >= self::HEALTHY_BELOW_PERCENT) {
            return PlatformHealthStatus::Warning;
        }

        return PlatformHealthStatus::Healthy;
    }

    /**
     * Weighted health score (0–100, higher is better). Equal weight per KPI card.
     *
     * @param  list<PlatformCardPayload>  $cards
     */
    public static function healthScorePercent(array $cards): ?float
    {
        if ($cards === []) {
            return null;
        }

        $totalWeight = 0;
        $healthyWeight = 0.0;

        foreach ($cards as $card) {
            $weight = self::weightFor($card);
            if ($weight <= 0) {
                continue;
            }

            $totalWeight += $weight;
            $healthyWeight += match ($card->status) {
                PlatformHealthStatus::Healthy => $weight,
                PlatformHealthStatus::Warning => $weight * 0.5,
                PlatformHealthStatus::Critical,
                PlatformHealthStatus::Disabled => 0.0,
            };
        }

        if ($totalWeight === 0) {
            return null;
        }

        return round(($healthyWeight / $totalWeight) * 100, 1);
    }

    /**
     * Operational pressure (0–100, higher is worse): 100 − health score.
     *
     * @param  list<PlatformCardPayload>  $cards
     */
    public static function operationalPressurePercent(array $cards): ?float
    {
        $health = self::healthScorePercent($cards);

        if ($health === null) {
            return null;
        }

        return round(100 - $health, 1);
    }

    private static function weightFor(PlatformCardPayload $card): int
    {
        return 1;
    }
}
