<?php

namespace App\Services\Dashboard;

use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Operations\OperationsQueueClassifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Cross-request cache for the operator dashboard.
 *
 * Fast path (cases / presence) uses a short-TTL active-incident snapshot.
 * The shared cache stores a plain-array projection only (never Eloquent graphs).
 * Slow path (admin table COUNTs) uses a separate short-TTL scalar cache so
 * live polls do not re-scan orders / users / audit_logs on every request.
 */
class OperatorDashboardCache
{
    public const SNAPSHOT_CACHE_KEY = 'operator.dashboard.snapshot:v2';

    public const SLOW_SCALARS_CACHE_KEY = 'operator.dashboard.slow_scalars:v1';

    public function __construct(
        private readonly ActiveIncidentSnapshotPayload $snapshotPayload,
    ) {}

    /**
     * @param  callable(): Collection<int, Incident>  $loader
     * @return Collection<int, Incident>
     */
    public function rememberActiveIncidents(callable $loader): Collection
    {
        return $this->rememberCachedSnapshot($loader)->incidents;
    }

    /**
     * @param  callable(): Collection<int, Incident>  $loader
     */
    public function rememberCachedSnapshot(callable $loader): CachedActiveIncidentSnapshot
    {
        if (! $this->snapshotCacheEnabled()) {
            return $this->buildCachedSnapshot($loader());
        }

        $cached = Cache::get(self::SNAPSHOT_CACHE_KEY);

        if ($this->snapshotPayload->isValidPayload($cached)) {
            $decoded = $this->snapshotPayload->decodeCached($cached);

            if ($decoded instanceof CachedActiveIncidentSnapshot) {
                return $decoded;
            }
        }

        $built = $this->buildCachedSnapshot($loader());

        $this->storeSnapshotCache(
            $this->snapshotPayload->encode(
                $built->incidents,
                $built->queueCounts,
                $built->slaCounts,
            ),
        );

        return $built;
    }

    public function forgetSnapshot(): void
    {
        Cache::forget(self::SNAPSHOT_CACHE_KEY);
        Cache::forget('operator.dashboard.snapshot:v1');
    }

    public function forgetSlowScalars(): void
    {
        Cache::forget(self::SLOW_SCALARS_CACHE_KEY);
    }

    public function forgetAll(): void
    {
        $this->forgetSnapshot();
        $this->forgetSlowScalars();
    }

    public function snapshotCacheEnabled(): bool
    {
        return (bool) config('dashboard.snapshot_cache_enabled', true);
    }

    public function snapshotTtlSeconds(): int
    {
        $ttl = (int) config('dashboard.snapshot_cache_ttl_seconds', 30);

        return max(15, min(30, $ttl));
    }

    public function slowScalarsTtlSeconds(): int
    {
        $ttl = (int) config('dashboard.slow_scalars_cache_ttl_seconds', 30);

        return max(15, min(60, $ttl));
    }

    /**
     * @return array{total_orders: int, total_users: int, audit_log_count: int}
     */
    public function slowScalars(): array
    {
        /** @var array{total_orders: int, total_users: int, audit_log_count: int} */
        return Cache::remember(
            self::SLOW_SCALARS_CACHE_KEY,
            now()->addSeconds($this->slowScalarsTtlSeconds()),
            fn (): array => $this->loadSlowScalars(),
        );
    }

    /**
     * @param  array{v: int, incidents: list<array<string, mixed>>, queue_counts?: array<string, int>, sla_counts?: array<string, int>}  $payload
     */
    private function storeSnapshotCache(array $payload): void
    {
        try {
            Cache::put(
                self::SNAPSHOT_CACHE_KEY,
                $payload,
                now()->addSeconds($this->snapshotTtlSeconds()),
            );

            Cache::forget('operator.dashboard.snapshot:v1');
        } catch (\Throwable $exception) {
            // Production cache tables may use TEXT-sized value columns; large
            // active-incident snapshots must not break dashboard/login redirects.
            report($exception);
        }
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     */
    private function buildCachedSnapshot(Collection $incidents): CachedActiveIncidentSnapshot
    {
        $classifier = app(OperationsQueueClassifier::class)->rememberClassifications();
        $snapshot = new DashboardSnapshot($incidents, $classifier);
        $queueCounts = $snapshot->queueCounts();
        $slaCounts = $snapshot->slaCounts();

        return new CachedActiveIncidentSnapshot(
            incidents: $incidents instanceof \Illuminate\Database\Eloquent\Collection
                ? $incidents
                : new \Illuminate\Database\Eloquent\Collection($incidents->all()),
            queueCounts: $queueCounts,
            slaCounts: $slaCounts,
        );
    }

    /**
     * @return array{total_orders: int, total_users: int, audit_log_count: int}
     */
    private function loadSlowScalars(): array
    {
        return [
            'total_orders' => Order::query()->count(),
            'total_users' => User::query()->count(),
            'audit_log_count' => AuditLog::query()->count(),
        ];
    }
}
