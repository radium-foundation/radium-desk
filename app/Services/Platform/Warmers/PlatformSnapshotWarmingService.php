<?php

namespace App\Services\Platform\Warmers;

use App\Contracts\Platform\PlatformSnapshotWarmer;
use App\Models\User;
use App\Services\Platform\PlatformCacheInvalidator;
use App\Services\Platform\PlatformCachePolicy;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PlatformSnapshotWarmingService
{
    public function __construct(
        private readonly PlatformSnapshotWarmerRegistry $registry,
        private readonly PlatformCacheInvalidator $invalidator,
        private readonly PlatformZoneSnapshotStore $snapshotStore,
    ) {}

    /**
     * @return array{warmed: list<string>, skipped: list<string>, failed: list<string>, actor_id: ?int, synthetic_actor: bool}
     */
    public function warmAll(?User $actor = null): array
    {
        $actor ??= PlatformWarmingActor::resolve();
        $warmed = [];
        $skipped = [];
        $failed = [];

        foreach ($this->registry->all() as $warmer) {
            if ($this->isFresh($warmer)) {
                $skipped[] = $warmer->key();
                Log::info('platform.snapshot_warmer_skipped_fresh', ['warmer' => $warmer->key()]);

                continue;
            }

            $lock = null;
            $acquired = true;

            try {
                $lock = Cache::lock(PlatformCachePolicy::warmLockKey($warmer->key()), 55);
                $acquired = $lock->get();
            } catch (\Throwable) {
                // Array/file stores may not support locks — continue without.
                $lock = null;
                $acquired = true;
            }

            if (! $acquired) {
                Log::info('platform.snapshot_warmer_skipped_lock', ['warmer' => $warmer->key()]);
                $skipped[] = $warmer->key();

                continue;
            }

            try {
                $warmer->warm($actor);
                $warmed[] = $warmer->key();
            } catch (\Throwable $exception) {
                report($exception);
                $failed[] = $warmer->key();
                $this->invalidator->markZoneStale($warmer->key());
                Log::warning('platform.snapshot_warmer_failed', [
                    'warmer' => $warmer->key(),
                    'message' => $exception->getMessage(),
                ]);
            } finally {
                try {
                    $lock?->release();
                } catch (\Throwable) {
                    // ignore
                }
            }
        }

        return [
            'warmed' => $warmed,
            'skipped' => $skipped,
            'failed' => $failed,
            'actor_id' => PlatformWarmingActor::isSynthetic($actor) ? null : $actor->id,
            'synthetic_actor' => PlatformWarmingActor::isSynthetic($actor),
        ];
    }

    /**
     * Honor existing zone TTLs: cron is every minute, P1 TTL is 120s / P3 is 300s.
     * Skip rebuild when the zone snapshot (and related overview keys) are still warm.
     */
    private function isFresh(PlatformSnapshotWarmer $warmer): bool
    {
        $zoneKey = $warmer->key();
        $snapshot = $this->snapshotStore->get($zoneKey);

        if ($snapshot === null || ! $snapshot->available || $snapshot->stale) {
            return false;
        }

        foreach (PlatformCachePolicy::relatedOverviewKeys($zoneKey) as $key) {
            // Contributor zones intentionally forget overall-health on store;
            // only the critical_alerts warmer is responsible for that key.
            if ($key === PlatformCachePolicy::KEY_OVERALL_HEALTH && $zoneKey !== 'critical_alerts') {
                continue;
            }

            if (! Cache::has($key)) {
                return false;
            }
        }

        return true;
    }
}
