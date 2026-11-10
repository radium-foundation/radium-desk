<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareFulfilmentState;
use App\Models\HardwareFulfilment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Short-TTL cache for hardware dashboard tab counts. Read-only.
 */
final class HardwareDashboardCountsCache
{
    public function __construct(
        private readonly HardwareNeedsActionSqlQuery $needsActionSql,
        private readonly HardwareFulfilmentWorkQueue $workQueue,
    ) {}

    /**
     * @return array<string, int>
     */
    public function filterCounts(Carbon $fromIst, Carbon $toIst, string $search = ''): array
    {
        $ttl = max(5, (int) config('dashboard.hardware_counts_ttl_seconds', 30));
        $key = 'hardware.dashboard.filter_counts.'.sha1(
            $fromIst->toIso8601String().'|'.$toIst->toIso8601String().'|'.strtoupper(trim($search)),
        );

        return Cache::remember(
            $key,
            $ttl,
            fn (): array => $this->needsActionSql->filterCounts($fromIst, $toIst, $search),
        );
    }

    /**
     * @return array{active: int, shipped: int}
     */
    public function scopeCounts(Carbon $fromIst, Carbon $toIst): array
    {
        $ttl = max(5, (int) config('dashboard.hardware_counts_ttl_seconds', 30));
        $key = 'hardware.dashboard.scope_counts.'.sha1(
            $fromIst->toIso8601String().'|'.$toIst->toIso8601String(),
        );

        return Cache::remember($key, $ttl, fn (): array => [
            'active' => $this->workQueue->workspaceTotal($fromIst, $toIst),
            'shipped' => HardwareFulfilment::query()
                ->whereIn('state', [
                    HardwareFulfilmentState::Shipped->value,
                    HardwareFulfilmentState::Synced->value,
                ])
                ->count(),
        ]);
    }
}
