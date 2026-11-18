<?php

namespace App\Services\HardwareFulfilment;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Cross-request stale-while-revalidate snapshot for admin Hardware Stuck Orders KPI.
 * Read-only reporting — never mutates fulfilment data.
 */
final class HardwareStuckOrdersSnapshotStore
{
    private const SNAPSHOT_KEY = 'hardware.dashboard.stuck_summary.snapshot';

    private const LAST_KNOWN_KEY = 'hardware.dashboard.stuck_summary.last_known';

    private const VERSION_KEY = 'hardware.dashboard.stuck_summary.version';

    private const REFRESH_LOCK_KEY = 'hardware.dashboard.stuck_summary.refresh';

    /**
     * @return array{
     *     summary: array{needs_action_total: int, breakdown: list<array{filter: string, label: string, count: int, tone: string}>},
     *     captured_at: int,
     *     version: int,
     *     stale: bool,
     *     source: string
     * }|null
     */
    public function presentationForRender(): ?array
    {
        $snapshot = $this->readSnapshot();
        if ($snapshot !== null) {
            return [
                'summary' => $snapshot['summary'],
                'captured_at' => $snapshot['captured_at'],
                'version' => $snapshot['version'],
                'stale' => $this->isExpired($snapshot),
                'source' => 'snapshot',
            ];
        }

        $lastKnown = $this->readLastKnown();
        if ($lastKnown === null) {
            return null;
        }

        return [
            'summary' => $lastKnown['summary'],
            'captured_at' => $lastKnown['captured_at'],
            'version' => $lastKnown['version'],
            'stale' => true,
            'source' => 'last_known',
        ];
    }

    public function shouldQueueBackgroundRefresh(): bool
    {
        $snapshot = $this->readSnapshot();
        if ($snapshot === null) {
            return true;
        }

        return $this->isExpired($snapshot);
    }

    /**
     * @param  array{needs_action_total: int, breakdown: list<array{filter: string, label: string, count: int, tone: string}>}  $summary
     */
    public function storeSuccessfulRefresh(array $summary): int
    {
        $version = $this->nextVersion();
        $capturedAt = now()->getTimestamp();
        $payload = [
            'summary' => $summary,
            'captured_at' => $capturedAt,
            'version' => $version,
        ];

        $this->put(self::SNAPSHOT_KEY, $payload, $this->snapshotTtlSeconds());
        $this->put(self::LAST_KNOWN_KEY, $payload, $this->lastKnownTtlSeconds());

        return $version;
    }

    public function acquireRefreshLock(int $blockSeconds = 0): bool
    {
        try {
            $lock = Cache::lock(self::REFRESH_LOCK_KEY, $this->refreshLockSeconds());
            if ($blockSeconds > 0) {
                return $lock->block($blockSeconds);
            }

            return $lock->get();
        } catch (Throwable) {
            return $blockSeconds <= 0;
        }
    }

    /**
     * Poll briefly when another request is refreshing a cold snapshot.
     *
     * @return array{
     *     summary: array{needs_action_total: int, breakdown: list<array{filter: string, label: string, count: int, tone: string}>},
     *     captured_at: int,
     *     version: int,
     *     stale: bool,
     *     source: string
     * }|null
     */
    public function waitForPresentation(int $maxWaitSeconds = 3): ?array
    {
        $deadline = microtime(true) + max(0, $maxWaitSeconds);

        do {
            $presentation = $this->presentationForRender();
            if ($presentation !== null) {
                return $presentation;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    public function releaseRefreshLock(): void
    {
        try {
            Cache::lock(self::REFRESH_LOCK_KEY)->forceRelease();
        } catch (Throwable) {
            // Best-effort.
        }
    }

    /**
     * @return array{summary: array, captured_at: int, version: int}|null
     */
    private function readSnapshot(): ?array
    {
        return $this->normalizePayload($this->read(self::SNAPSHOT_KEY));
    }

    /**
     * @return array{summary: array, captured_at: int, version: int}|null
     */
    private function readLastKnown(): ?array
    {
        return $this->normalizePayload($this->read(self::LAST_KNOWN_KEY));
    }

    /**
     * @param  array{summary?: mixed, captured_at?: mixed, version?: mixed}|null  $payload
     * @return array{summary: array, captured_at: int, version: int}|null
     */
    private function normalizePayload(?array $payload): ?array
    {
        if ($payload === null || ! is_array($payload['summary'] ?? null)) {
            return null;
        }

        $capturedAt = (int) ($payload['captured_at'] ?? 0);
        $version = (int) ($payload['version'] ?? 0);
        if ($capturedAt <= 0 || $version <= 0) {
            return null;
        }

        return [
            'summary' => $payload['summary'],
            'captured_at' => $capturedAt,
            'version' => $version,
        ];
    }

    /**
     * @param  array{summary: array, captured_at: int, version: int}  $payload
     */
    private function isExpired(array $payload): bool
    {
        return (now()->getTimestamp() - $payload['captured_at']) >= $this->snapshotTtlSeconds();
    }

    private function nextVersion(): int
    {
        try {
            $next = Cache::increment(self::VERSION_KEY);
            if (is_int($next) && $next > 0) {
                return $next;
            }
        } catch (Throwable) {
            // Fall through.
        }

        return now()->getTimestamp();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $key): ?array
    {
        try {
            $payload = Cache::get($key);
        } catch (Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function put(string $key, array $payload, int $ttlSeconds): void
    {
        try {
            Cache::put($key, $payload, $ttlSeconds);
        } catch (Throwable) {
            // Best-effort cache only.
        }
    }

    private function snapshotTtlSeconds(): int
    {
        return max(5, (int) config('dashboard.hardware_stuck_snapshot_ttl_seconds', 30));
    }

    private function lastKnownTtlSeconds(): int
    {
        return max(300, (int) config('dashboard.hardware_stuck_last_known_ttl_seconds', 86400));
    }

    private function refreshLockSeconds(): int
    {
        return max(30, (int) config('dashboard.hardware_stuck_refresh_lock_seconds', 120));
    }
}
