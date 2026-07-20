<?php

namespace App\Services\Platform;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class PlatformHealthCache
{
    public const SCHEDULER_LAST_RUN_AT = 'operations:scheduler:last_run_at';

    public const PRESENCE_LAST_TIMEOUT_RUN_AT = 'operations:presence:last_timeout_run_at';

    public const PRESENCE_LAST_TIMEOUT_PROCESSED = 'operations:presence:last_timeout_processed';

    public const PRESENCE_STALE_SESSION_COUNT = 'operations:presence:stale_session_count';

    private const TTL_SECONDS = 3600;

    public static function recordSchedulerHeartbeat(?Carbon $at = null): void
    {
        Cache::put(self::SCHEDULER_LAST_RUN_AT, ($at ?? now())->toIso8601String(), self::TTL_SECONDS);
    }

    public static function schedulerLastRunAt(): ?Carbon
    {
        return self::parseTimestamp(Cache::get(self::SCHEDULER_LAST_RUN_AT));
    }

    public static function recordPresenceTimeoutRun(int $processed, int $staleCount, ?Carbon $at = null): void
    {
        $at ??= now();

        Cache::put(self::PRESENCE_LAST_TIMEOUT_RUN_AT, $at->toIso8601String(), self::TTL_SECONDS);
        Cache::put(self::PRESENCE_LAST_TIMEOUT_PROCESSED, $processed, self::TTL_SECONDS);
        Cache::put(self::PRESENCE_STALE_SESSION_COUNT, $staleCount, self::TTL_SECONDS);
    }

    public static function presenceLastTimeoutRunAt(): ?Carbon
    {
        return self::parseTimestamp(Cache::get(self::PRESENCE_LAST_TIMEOUT_RUN_AT));
    }

    public static function presenceLastTimeoutProcessed(): int
    {
        return (int) Cache::get(self::PRESENCE_LAST_TIMEOUT_PROCESSED, 0);
    }

    public static function presenceStaleSessionCount(): int
    {
        return (int) Cache::get(self::PRESENCE_STALE_SESSION_COUNT, 0);
    }

    private static function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
