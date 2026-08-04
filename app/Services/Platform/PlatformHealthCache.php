<?php

namespace App\Services\Platform;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Scheduler / presence heartbeat markers for Platform Health probes.
 *
 * Fast path: application cache.
 * Durable path: storage file — survives `php artisan optimize:clear` / `cache:clear`
 * so HTTP zone refresh cannot persist a false Critical snapshot after a cache flush.
 */
class PlatformHealthCache
{
    public const SCHEDULER_LAST_RUN_AT = 'operations:scheduler:last_run_at';

    public const PRESENCE_LAST_TIMEOUT_RUN_AT = 'operations:presence:last_timeout_run_at';

    public const PRESENCE_LAST_TIMEOUT_PROCESSED = 'operations:presence:last_timeout_processed';

    public const PRESENCE_STALE_SESSION_COUNT = 'operations:presence:stale_session_count';

    private const TTL_SECONDS = 3600;

    private const DURABLE_FILENAME = 'platform-health-heartbeats.json';

    public static function recordSchedulerHeartbeat(?Carbon $at = null): void
    {
        $iso = ($at ?? now())->toIso8601String();
        Cache::put(self::SCHEDULER_LAST_RUN_AT, $iso, self::TTL_SECONDS);
        self::writeDurable(['scheduler_last_run_at' => $iso]);
    }

    public static function schedulerLastRunAt(): ?Carbon
    {
        return self::parseTimestamp(Cache::get(self::SCHEDULER_LAST_RUN_AT))
            ?? self::parseTimestamp(self::readDurable('scheduler_last_run_at'));
    }

    public static function recordPresenceTimeoutRun(int $processed, int $staleCount, ?Carbon $at = null): void
    {
        $at ??= now();
        $iso = $at->toIso8601String();

        Cache::put(self::PRESENCE_LAST_TIMEOUT_RUN_AT, $iso, self::TTL_SECONDS);
        Cache::put(self::PRESENCE_LAST_TIMEOUT_PROCESSED, $processed, self::TTL_SECONDS);
        Cache::put(self::PRESENCE_STALE_SESSION_COUNT, $staleCount, self::TTL_SECONDS);

        self::writeDurable([
            'presence_last_timeout_run_at' => $iso,
            'presence_last_timeout_processed' => $processed,
            'presence_stale_session_count' => $staleCount,
        ]);
    }

    public static function presenceLastTimeoutRunAt(): ?Carbon
    {
        return self::parseTimestamp(Cache::get(self::PRESENCE_LAST_TIMEOUT_RUN_AT))
            ?? self::parseTimestamp(self::readDurable('presence_last_timeout_run_at'));
    }

    public static function presenceLastTimeoutProcessed(): int
    {
        if (Cache::has(self::PRESENCE_LAST_TIMEOUT_PROCESSED)) {
            return (int) Cache::get(self::PRESENCE_LAST_TIMEOUT_PROCESSED, 0);
        }

        return (int) (self::readDurable('presence_last_timeout_processed') ?? 0);
    }

    public static function presenceStaleSessionCount(): int
    {
        if (Cache::has(self::PRESENCE_STALE_SESSION_COUNT)) {
            return (int) Cache::get(self::PRESENCE_STALE_SESSION_COUNT, 0);
        }

        return (int) (self::readDurable('presence_stale_session_count') ?? 0);
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private static function writeDurable(array $patch): void
    {
        $path = self::durablePath();
        $directory = dirname($path);

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $current = [];
        if (File::exists($path)) {
            $decoded = json_decode((string) File::get($path), true);
            if (is_array($decoded)) {
                $current = $decoded;
            }
        }

        File::put($path, json_encode(array_merge($current, $patch), JSON_THROW_ON_ERROR));
    }

    private static function readDurable(string $key): mixed
    {
        $path = self::durablePath();

        if (! File::exists($path)) {
            return null;
        }

        $decoded = json_decode((string) File::get($path), true);
        if (! is_array($decoded)) {
            return null;
        }

        return $decoded[$key] ?? null;
    }

    private static function durablePath(): string
    {
        if (app()->runningUnitTests()) {
            return storage_path('framework/testing/'.self::DURABLE_FILENAME);
        }

        return storage_path('framework/platform-health/'.self::DURABLE_FILENAME);
    }

    /**
     * Test helper — clears durable heartbeat file (survives Cache::flush).
     */
    public static function clearDurableForTests(): void
    {
        $path = self::durablePath();

        if (File::exists($path)) {
            File::delete($path);
        }
    }

    private static function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
