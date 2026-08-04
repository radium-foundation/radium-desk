<?php

namespace App\Services\Platform\Health;

use App\Data\Platform\PlatformHealthContribution;
use App\Data\Platform\PlatformOverallHealth;
use App\Enums\PlatformOverallHealthStatus;
use App\Services\Platform\PlatformCachePolicy;
use App\Support\Platform\PlatformCacheAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class PlatformOverallHealthService
{
    public const CACHE_KEY = PlatformCachePolicy::KEY_OVERALL_HEALTH;

    public const CACHE_TTL_SECONDS = PlatformCachePolicy::TTL_PRIORITY_1;

    public function __construct(
        private readonly PlatformOverallHealthRegistry $registry,
    ) {}

    /**
     * Cache-only overall health. Never probes.
     */
    public function summarize(bool $useCache = true): PlatformOverallHealth
    {
        if ($useCache) {
            $cached = Cache::get(self::CACHE_KEY);
            PlatformCacheAudit::read(
                service: self::class,
                method: 'summarize',
                cacheKey: self::CACHE_KEY,
                payload: is_array($cached) ? $cached : null,
                hit: is_array($cached) && isset($cached['status']),
            );
            if (is_array($cached) && isset($cached['status'])) {
                return $this->fromCachePayload($cached);
            }
        }

        $health = $this->compute();
        $this->store($health);

        return $health;
    }

    public function compute(): PlatformOverallHealth
    {
        $contributions = [];

        foreach ($this->registry->all() as $contributor) {
            $contribution = $contributor->contribute();
            if ($contribution !== null) {
                $contributions[] = $contribution;
            }
        }

        if ($contributions === []) {
            return new PlatformOverallHealth(
                status: PlatformOverallHealthStatus::Unavailable,
                statusLabel: PlatformOverallHealthStatus::Unavailable->label(),
                scorePercent: null,
                available: false,
                stale: false,
                updatedAt: null,
                contributions: [],
                summary: 'Waiting for background refresh.',
            );
        }

        $available = array_values(array_filter(
            $contributions,
            static fn (PlatformHealthContribution $c): bool => $c->available,
        ));

        if ($available === []) {
            return new PlatformOverallHealth(
                status: PlatformOverallHealthStatus::Unavailable,
                statusLabel: PlatformOverallHealthStatus::Unavailable->label(),
                scorePercent: null,
                available: false,
                stale: $this->anyStale($contributions),
                updatedAt: $this->latestUpdatedAt($contributions),
                contributions: $contributions,
                summary: 'Waiting for background refresh.',
            );
        }

        $statuses = array_map(
            static fn (PlatformHealthContribution $c): PlatformOverallHealthStatus => $c->status,
            $available,
        );
        $overall = PlatformOverallHealthStatus::worst(...$statuses);
        $score = $this->scorePercent($available);
        $stale = $this->anyStale($contributions);

        $summary = match ($overall) {
            PlatformOverallHealthStatus::Healthy => 'All monitored surfaces are healthy.',
            PlatformOverallHealthStatus::Warning => 'One or more surfaces need attention.',
            PlatformOverallHealthStatus::Critical => 'Critical issues require attention.',
            PlatformOverallHealthStatus::Unavailable => 'Waiting for background refresh.',
        };

        if ($stale) {
            $summary .= ' Some snapshots are stale — retry pending.';
        }

        return new PlatformOverallHealth(
            status: $overall,
            statusLabel: $overall->label(),
            scorePercent: $score,
            available: true,
            stale: $stale,
            updatedAt: $this->latestUpdatedAt($contributions),
            contributions: $contributions,
            summary: $summary,
        );
    }

    public function store(PlatformOverallHealth $health): void
    {
        $new = $health->toArray();
        $old = Cache::get(self::CACHE_KEY);

        PlatformCacheAudit::write(
            service: self::class,
            method: 'store',
            cacheKey: self::CACHE_KEY,
            oldPayload: is_array($old) ? $old : null,
            newPayload: $new,
        );

        Cache::put(self::CACHE_KEY, $new, now()->addSeconds(self::CACHE_TTL_SECONDS));
    }

    /**
     * @param  list<PlatformHealthContribution>  $contributions
     */
    private function scorePercent(array $contributions): ?float
    {
        $totalWeight = 0;
        $healthyWeight = 0;

        foreach ($contributions as $contribution) {
            if (! $contribution->available) {
                continue;
            }

            $weight = max(1, $contribution->weight);
            $totalWeight += $weight;

            $healthyWeight += match ($contribution->status) {
                PlatformOverallHealthStatus::Healthy => $weight,
                PlatformOverallHealthStatus::Warning => $weight * 0.5,
                PlatformOverallHealthStatus::Critical => 0.0,
                PlatformOverallHealthStatus::Unavailable => 0.0,
            };
        }

        if ($totalWeight < 2) {
            return null;
        }

        return round(($healthyWeight / $totalWeight) * 100, 1);
    }

    /**
     * @param  list<PlatformHealthContribution>  $contributions
     */
    private function anyStale(array $contributions): bool
    {
        foreach ($contributions as $contribution) {
            if ($contribution->stale) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<PlatformHealthContribution>  $contributions
     */
    private function latestUpdatedAt(array $contributions): ?Carbon
    {
        $latest = null;

        foreach ($contributions as $contribution) {
            if ($contribution->updatedAt === null) {
                continue;
            }
            if ($latest === null || $contribution->updatedAt->greaterThan($latest)) {
                $latest = $contribution->updatedAt;
            }
        }

        return $latest;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fromCachePayload(array $payload): PlatformOverallHealth
    {
        $status = PlatformOverallHealthStatus::tryFrom((string) $payload['status'])
            ?? PlatformOverallHealthStatus::Unavailable;

        $updatedAt = null;
        if (! empty($payload['updated_at']) && is_string($payload['updated_at'])) {
            try {
                $updatedAt = Carbon::parse($payload['updated_at']);
            } catch (\Throwable) {
                $updatedAt = null;
            }
        }

        $contributions = [];
        foreach ($payload['contributions'] ?? [] as $row) {
            if (! is_array($row) || ! isset($row['source'], $row['status'])) {
                continue;
            }

            $contribStatus = PlatformOverallHealthStatus::tryFrom((string) $row['status'])
                ?? PlatformOverallHealthStatus::Unavailable;

            $contribUpdated = null;
            if (! empty($row['updated_at']) && is_string($row['updated_at'])) {
                try {
                    $contribUpdated = Carbon::parse($row['updated_at']);
                } catch (\Throwable) {
                    $contribUpdated = null;
                }
            }

            $contributions[] = new PlatformHealthContribution(
                source: (string) $row['source'],
                label: (string) ($row['label'] ?? $row['source']),
                status: $contribStatus,
                available: (bool) ($row['available'] ?? false),
                updatedAt: $contribUpdated,
                stale: (bool) ($row['stale'] ?? false),
                weight: (int) ($row['weight'] ?? 1),
            );
        }

        return new PlatformOverallHealth(
            status: $status,
            statusLabel: (string) ($payload['status_label'] ?? $status->label()),
            scorePercent: isset($payload['score_percent']) ? (float) $payload['score_percent'] : null,
            available: (bool) ($payload['available'] ?? false),
            stale: (bool) ($payload['stale'] ?? false),
            updatedAt: $updatedAt,
            contributions: $contributions,
            summary: (string) ($payload['summary'] ?? ''),
        );
    }
}
