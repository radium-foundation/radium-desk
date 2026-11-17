<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareFulfilmentState;
use App\Jobs\HardwareFulfilment\RefreshHardwareDashboardNavCountsJob;
use App\Models\HardwareFulfilment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Short-TTL cache for hardware dashboard tab counts. Read-only.
 */
final class HardwareDashboardCountsCache
{
    /** @var array<string, array<string, int>> */
    private array $requestFilterCounts = [];

    /** @var array<string, array{active: int, shipped: int}> */
    private array $requestScopeCounts = [];

    public function __construct(
        private readonly HardwareNeedsActionSqlQuery $needsActionSql,
        private readonly HardwareFulfilmentWorkQueue $workQueue,
    ) {}

    /**
     * Cached or last-known counts without blocking on cold SQL (SWR for progressive first chunk).
     *
     * @return array{
     *     scope_counts: array{active: int, shipped: int},
     *     filter_counts: array<string, int>,
     *     stale: bool,
     *     refresh_queued: bool
     * }
     */
    public function presentationCounts(Carbon $fromIst, Carbon $toIst, string $search = ''): array
    {
        $filterKey = $this->filterCountsCacheKey($fromIst, $toIst, $search);
        $scopeKey = $this->scopeCountsCacheKey($fromIst, $toIst);

        $filter = Cache::get($filterKey);
        $scope = Cache::get($scopeKey);
        $stale = false;
        $refreshQueued = false;

        if (! is_array($filter)) {
            $filter = Cache::get($filterKey.'.last_known');
            if (is_array($filter)) {
                $stale = true;
            } else {
                $filter = [];
                $stale = true;
                $refreshQueued = true;
            }
        }

        if (! is_array($scope)) {
            $scope = Cache::get($scopeKey.'.last_known');
            if (is_array($scope)) {
                $stale = true;
            } else {
                $scope = ['active' => 0, 'shipped' => 0];
                $stale = true;
                $refreshQueued = true;
            }
        }

        if ($refreshQueued) {
            $this->queueBackgroundRefresh($fromIst, $toIst, $search);
        }

        return [
            'scope_counts' => $scope,
            'filter_counts' => $filter,
            'stale' => $stale,
            'refresh_queued' => $refreshQueued,
        ];
    }

    public function queueBackgroundRefresh(Carbon $fromIst, Carbon $toIst, string $search = ''): void
    {
        RefreshHardwareDashboardNavCountsJob::dispatch(
            $fromIst->toIso8601String(),
            $toIst->toIso8601String(),
            $search,
        )->afterResponse();
    }

    /**
     * Warm live filter + scope counts (SQL) and store last-known snapshots.
     */
    public function warmLiveCounts(Carbon $fromIst, Carbon $toIst, string $search = ''): void
    {
        $this->filterCounts($fromIst, $toIst, $search);
        $this->scopeCounts($fromIst, $toIst);
    }

    /**
     * @return array<string, int>
     */
    public function filterCounts(Carbon $fromIst, Carbon $toIst, string $search = ''): array
    {
        $ttl = $this->countsTtlSeconds();
        $memoKey = 'filter|'.$this->rangeCacheIdentity($fromIst, $toIst).'|'.strtoupper(trim($search));

        if (array_key_exists($memoKey, $this->requestFilterCounts)) {
            return $this->requestFilterCounts[$memoKey];
        }

        $key = $this->filterCountsCacheKey($fromIst, $toIst, $search);

        $counts = Cache::remember(
            $key,
            $ttl,
            fn (): array => $this->needsActionSql->filterCounts($fromIst, $toIst, $search),
        );

        Cache::put($key.'.last_known', $counts, $this->lastKnownTtlSeconds());

        return $this->requestFilterCounts[$memoKey] = $counts;
    }

    /**
     * @return array{active: int, shipped: int}
     */
    public function scopeCounts(Carbon $fromIst, Carbon $toIst): array
    {
        $ttl = $this->countsTtlSeconds();
        $memoKey = 'scope|'.$this->rangeCacheIdentity($fromIst, $toIst);

        if (array_key_exists($memoKey, $this->requestScopeCounts)) {
            return $this->requestScopeCounts[$memoKey];
        }

        $key = $this->scopeCountsCacheKey($fromIst, $toIst);

        $counts = Cache::remember($key, $ttl, fn (): array => [
            'active' => $this->workQueue->workspaceTotal($fromIst, $toIst),
            'shipped' => HardwareFulfilment::query()
                ->whereIn('state', [
                    HardwareFulfilmentState::Shipped->value,
                    HardwareFulfilmentState::Synced->value,
                ])
                ->count(),
        ]);

        Cache::put($key.'.last_known', $counts, $this->lastKnownTtlSeconds());

        return $this->requestScopeCounts[$memoKey] = $counts;
    }

    private function filterCountsCacheKey(Carbon $fromIst, Carbon $toIst, string $search): string
    {
        $identity = $this->rangeCacheIdentity($fromIst, $toIst);

        return 'hardware.dashboard.filter_counts.'.sha1(
            $identity.'|'.strtoupper(trim($search)),
        );
    }

    private function scopeCountsCacheKey(Carbon $fromIst, Carbon $toIst): string
    {
        return 'hardware.dashboard.scope_counts.'.sha1($this->rangeCacheIdentity($fromIst, $toIst));
    }

    /**
     * Stable cache identity: query range uses real instants; keys bucket "to" per TTL.
     */
    public function rangeCacheIdentity(Carbon $fromIst, Carbon $toIst): string
    {
        $ttl = $this->countsTtlSeconds();
        $bucketStart = intdiv($toIst->timestamp, $ttl) * $ttl;
        $bucketTo = Carbon::createFromTimestamp($bucketStart, $toIst->timezone);

        return $fromIst->toIso8601String().'|'.$bucketTo->toIso8601String();
    }

    private function countsTtlSeconds(): int
    {
        return max(5, (int) config('dashboard.hardware_counts_ttl_seconds', 30));
    }

    private function lastKnownTtlSeconds(): int
    {
        return max(60, (int) config('dashboard.hardware_counts_last_known_ttl_seconds', 86400));
    }
}
