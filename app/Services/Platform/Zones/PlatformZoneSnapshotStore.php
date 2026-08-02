<?php

namespace App\Services\Platform\Zones;

use App\Data\Platform\PlatformZoneSnapshot;
use App\Services\Platform\PlatformCachePolicy;
use Illuminate\Support\Facades\Cache;

class PlatformZoneSnapshotStore
{
    public function cacheKey(string $zoneKey): string
    {
        return PlatformCachePolicy::zoneSnapshotKey($zoneKey);
    }

    public function ttlFor(string $zoneKey): int
    {
        return PlatformCachePolicy::ttlForZone($zoneKey);
    }

    public function get(string $zoneKey): ?PlatformZoneSnapshot
    {
        $payload = Cache::get($this->cacheKey($zoneKey));

        if (! is_array($payload)) {
            return null;
        }

        return PlatformZoneSnapshot::fromCachePayload($zoneKey, $payload);
    }

    public function put(PlatformZoneSnapshot $snapshot): void
    {
        Cache::put(
            $this->cacheKey($snapshot->key),
            [
                'status' => $snapshot->status->value,
                'status_label' => $snapshot->statusLabel,
                'updated_at' => $snapshot->updatedAt?->toIso8601String() ?? now()->toIso8601String(),
                'summary' => $snapshot->summary,
                'html' => $snapshot->html,
                'available' => $snapshot->available,
                'stale' => $snapshot->stale,
            ],
            now()->addSeconds($this->ttlFor($snapshot->key)),
        );
    }

    /**
     * Keep last-known HTML/status but mark snapshot stale after a failed refresh.
     */
    public function markStale(string $zoneKey): bool
    {
        $payload = Cache::get($this->cacheKey($zoneKey));

        if (! is_array($payload) || ! isset($payload['status'], $payload['html'])) {
            return false;
        }

        $payload['stale'] = true;
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $summary['stale'] = true;
        $summary['retry_pending'] = true;
        $payload['summary'] = $summary;

        Cache::put($this->cacheKey($zoneKey), $payload, now()->addSeconds($this->ttlFor($zoneKey)));

        return true;
    }

    public function forget(string $zoneKey): void
    {
        Cache::forget($this->cacheKey($zoneKey));
    }
}
