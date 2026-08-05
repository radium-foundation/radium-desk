<?php

namespace App\Services\Dashboard;

use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
        if (! $this->snapshotCacheEnabled()) {
            return $loader();
        }

        $cached = Cache::get(self::SNAPSHOT_CACHE_KEY);

        if ($this->snapshotPayload->isValidPayload($cached)) {
            $decoded = $this->snapshotPayload->decode($cached);

            if ($decoded instanceof EloquentCollection) {
                return $decoded;
            }
        }

        $incidents = $loader();

        Cache::put(
            self::SNAPSHOT_CACHE_KEY,
            $this->snapshotPayload->encode($incidents),
            now()->addSeconds($this->snapshotTtlSeconds()),
        );

        // Drop any legacy Eloquent Collection payload from v1.
        Cache::forget('operator.dashboard.snapshot:v1');

        return $incidents;
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
        $ttl = (int) config('dashboard.snapshot_cache_ttl_seconds', 20);

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
    private function loadSlowScalars(): array
    {
        return [
            'total_orders' => Order::query()->count(),
            'total_users' => User::query()->count(),
            'audit_log_count' => AuditLog::query()->count(),
        ];
    }
}
