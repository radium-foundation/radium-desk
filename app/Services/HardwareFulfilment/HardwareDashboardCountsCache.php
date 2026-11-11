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
    /** @var array<string, array<string, int>> */
    private array $requestFilterCounts = [];

    /** @var array<string, array{active: int, shipped: int}> */
    private array $requestScopeCounts = [];

    public function __construct(
        private readonly HardwareNeedsActionSqlQuery $needsActionSql,
        private readonly HardwareFulfilmentWorkQueue $workQueue,
    ) {}

    /**
     * @return array<string, int>
     */
    public function filterCounts(Carbon $fromIst, Carbon $toIst, string $search = ''): array
    {
        $ttl = $this->countsTtlSeconds();
        $identity = $this->rangeCacheIdentity($fromIst, $toIst);
        $memoKey = 'filter|'.$identity.'|'.strtoupper(trim($search));

        if (array_key_exists($memoKey, $this->requestFilterCounts)) {
            return $this->requestFilterCounts[$memoKey];
        }

        $key = 'hardware.dashboard.filter_counts.'.sha1(
            $identity.'|'.strtoupper(trim($search)),
        );

        $counts = Cache::remember(
            $key,
            $ttl,
            fn (): array => $this->needsActionSql->filterCounts($fromIst, $toIst, $search),
        );

        return $this->requestFilterCounts[$memoKey] = $counts;
    }

    /**
     * @return array{active: int, shipped: int}
     */
    public function scopeCounts(Carbon $fromIst, Carbon $toIst): array
    {
        $ttl = $this->countsTtlSeconds();
        $identity = $this->rangeCacheIdentity($fromIst, $toIst);
        $memoKey = 'scope|'.$identity;

        if (array_key_exists($memoKey, $this->requestScopeCounts)) {
            return $this->requestScopeCounts[$memoKey];
        }

        $key = 'hardware.dashboard.scope_counts.'.sha1($identity);

        $counts = Cache::remember($key, $ttl, fn (): array => [
            'active' => $this->workQueue->workspaceTotal($fromIst, $toIst),
            'shipped' => HardwareFulfilment::query()
                ->whereIn('state', [
                    HardwareFulfilmentState::Shipped->value,
                    HardwareFulfilmentState::Synced->value,
                ])
                ->count(),
        ]);

        return $this->requestScopeCounts[$memoKey] = $counts;
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
}
