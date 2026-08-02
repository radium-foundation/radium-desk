<?php

namespace App\Services\Platform\Warmers;

use App\Models\User;
use App\Services\Platform\PlatformCacheInvalidator;
use App\Services\Platform\PlatformCachePolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PlatformSnapshotWarmingService
{
    public function __construct(
        private readonly PlatformSnapshotWarmerRegistry $registry,
        private readonly PlatformCacheInvalidator $invalidator,
    ) {}

    /**
     * @return array{warmed: list<string>, failed: list<string>, actor_id: ?int, synthetic_actor: bool}
     */
    public function warmAll(?User $actor = null): array
    {
        $actor ??= PlatformWarmingActor::resolve();
        $warmed = [];
        $failed = [];

        foreach ($this->registry->all() as $warmer) {
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
            'failed' => $failed,
            'actor_id' => PlatformWarmingActor::isSynthetic($actor) ? null : $actor->id,
            'synthetic_actor' => PlatformWarmingActor::isSynthetic($actor),
        ];
    }
}
