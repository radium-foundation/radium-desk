<?php

namespace App\Services\Platform\Zones;

use App\Data\Platform\PlatformZoneSnapshot;
use App\Services\Platform\PlatformCachePolicy;
use App\Support\Platform\PlatformCacheAudit;
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
        $key = $this->cacheKey($zoneKey);
        $payload = Cache::get($key);

        PlatformCacheAudit::read(
            service: self::class,
            method: 'get',
            cacheKey: $key,
            payload: is_array($payload) ? $payload : null,
            hit: is_array($payload),
        );

        if (! is_array($payload)) {
            return null;
        }

        return PlatformZoneSnapshot::fromCachePayload($zoneKey, $payload);
    }

    public function put(PlatformZoneSnapshot $snapshot): void
    {
        $key = $this->cacheKey($snapshot->key);
        $old = Cache::get($key);
        $new = [
            'status' => $snapshot->status->value,
            'status_label' => $snapshot->statusLabel,
            'updated_at' => $snapshot->updatedAt?->toIso8601String() ?? now()->toIso8601String(),
            'summary' => $snapshot->summary,
            'html' => $snapshot->html,
            'available' => $snapshot->available,
            'stale' => $snapshot->stale,
        ];

        PlatformCacheAudit::write(
            service: self::class,
            method: 'put',
            cacheKey: $key,
            oldPayload: is_array($old) ? $old : null,
            newPayload: $new,
        );

        Cache::put($key, $new, now()->addSeconds($this->ttlFor($snapshot->key)));
    }

    /**
     * Keep last-known HTML/status but mark snapshot stale after a failed refresh.
     */
    public function markStale(string $zoneKey): bool
    {
        $key = $this->cacheKey($zoneKey);
        $payload = Cache::get($key);

        if (! is_array($payload) || ! isset($payload['status'], $payload['html'])) {
            return false;
        }

        $old = $payload;
        $payload['stale'] = true;
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $summary['stale'] = true;
        $summary['retry_pending'] = true;
        $payload['summary'] = $summary;

        PlatformCacheAudit::write(
            service: self::class,
            method: 'markStale',
            cacheKey: $key,
            oldPayload: $old,
            newPayload: $payload,
        );

        Cache::put($key, $payload, now()->addSeconds($this->ttlFor($zoneKey)));

        return true;
    }

    public function forget(string $zoneKey): void
    {
        $key = $this->cacheKey($zoneKey);
        PlatformCacheAudit::forget(self::class, 'forget', $key);
        Cache::forget($key);
    }
}
