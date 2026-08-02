<?php

namespace App\Services\Platform;

use App\Enums\PlatformZoneId;

/**
 * Canonical Platform cache TTLs and key helpers.
 *
 * Production: use Redis (CACHE_STORE=redis). Database cache turns every
 * snapshot read into SQL and is not suitable for the zone framework.
 */
final class PlatformCachePolicy
{
    /** Priority 1 — Critical Alerts, Executive Snapshot, Platform Health, Overall Health. */
    public const TTL_PRIORITY_1 = 120;

    /** Priority 2 — Integration Health (+ per-item diagnostics). */
    public const TTL_PRIORITY_2 = 120;

    /** Priority 3 — Performance, Automation, Communications, Finance, Operations. */
    public const TTL_PRIORITY_3 = 300;

    /** Tools catalog is cheap; keep with priority 3. */
    public const TTL_TOOLS = 300;

    public const KEY_OVERALL_HEALTH = 'platform:overall-health';

    public const KEY_PLATFORM_HEALTH_OVERVIEW = 'platform:health:overview';

    public const KEY_INTEGRATION_OVERVIEW = 'platform:integration-health:overview';

    public const KEY_INTEGRATION_ITEM_PREFIX = 'platform:integration-health:item:';

    public const KEY_PERFORMANCE_OVERVIEW = 'platform:performance:overview';

    public const KEY_AUTOMATION_OVERVIEW = 'platform:automation:overview';

    public const KEY_COMMUNICATIONS_OVERVIEW = 'platform:communications:overview';

    public const KEY_FINANCE_OVERVIEW = 'platform:finance:overview';

    public const KEY_OPERATIONS_OVERVIEW = 'platform:operations-overview:overview';

    public const KEY_ZONE_SNAPSHOT_PREFIX = 'platform:zone:';

    public const KEY_ZONE_SNAPSHOT_SUFFIX = ':snapshot';

    public const KEY_WARM_LOCK_PREFIX = 'platform:warm:lock:';

    public static function ttlForZone(PlatformZoneId|string $zone): int
    {
        $id = $zone instanceof PlatformZoneId
            ? $zone
            : PlatformZoneId::tryFrom((string) $zone);

        if ($id === null) {
            return self::TTL_PRIORITY_3;
        }

        return match ($id->refreshPriority()) {
            1 => self::TTL_PRIORITY_1,
            2 => self::TTL_PRIORITY_2,
            default => self::TTL_PRIORITY_3,
        };
    }

    public static function ttlForOverviewKey(string $cacheKey): int
    {
        return match ($cacheKey) {
            self::KEY_PLATFORM_HEALTH_OVERVIEW,
            self::KEY_OVERALL_HEALTH => self::TTL_PRIORITY_1,
            self::KEY_INTEGRATION_OVERVIEW => self::TTL_PRIORITY_2,
            self::KEY_PERFORMANCE_OVERVIEW,
            self::KEY_AUTOMATION_OVERVIEW,
            self::KEY_COMMUNICATIONS_OVERVIEW,
            self::KEY_FINANCE_OVERVIEW,
            self::KEY_OPERATIONS_OVERVIEW => self::TTL_PRIORITY_3,
            default => str_starts_with($cacheKey, self::KEY_INTEGRATION_ITEM_PREFIX)
                ? self::TTL_PRIORITY_2
                : self::TTL_PRIORITY_3,
        };
    }

    public static function zoneSnapshotKey(string $zoneKey): string
    {
        return self::KEY_ZONE_SNAPSHOT_PREFIX.$zoneKey.self::KEY_ZONE_SNAPSHOT_SUFFIX;
    }

    public static function warmLockKey(string $warmerKey): string
    {
        return self::KEY_WARM_LOCK_PREFIX.$warmerKey;
    }

    /**
     * Overview keys tied to a zone — invalidated together on stale/refresh failure.
     *
     * @return list<string>
     */
    public static function relatedOverviewKeys(string $zoneKey): array
    {
        return match ($zoneKey) {
            'platform_health' => [self::KEY_PLATFORM_HEALTH_OVERVIEW, self::KEY_OVERALL_HEALTH],
            'critical_alerts' => [self::KEY_OVERALL_HEALTH],
            'integration_health' => [self::KEY_INTEGRATION_OVERVIEW, self::KEY_COMMUNICATIONS_OVERVIEW],
            'performance' => [self::KEY_PERFORMANCE_OVERVIEW],
            'automation' => [self::KEY_AUTOMATION_OVERVIEW],
            'communications' => [self::KEY_COMMUNICATIONS_OVERVIEW],
            'finance_overview' => [self::KEY_FINANCE_OVERVIEW],
            'operations_overview' => [self::KEY_OPERATIONS_OVERVIEW],
            'executive_snapshot' => [self::KEY_OVERALL_HEALTH],
            default => [],
        };
    }
}
