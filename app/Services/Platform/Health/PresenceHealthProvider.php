<?php

namespace App\Services\Platform\Health;

use App\Contracts\Platform\PlatformHealthProvider;
use App\Data\Platform\PlatformHealthComponent;
use App\Enums\PlatformHealthStatus;
use App\Models\WorkSession;
use App\Services\Platform\PlatformHealthCache;

class PresenceHealthProvider implements PlatformHealthProvider
{
    public function key(): string
    {
        return 'presence';
    }

    public function label(): string
    {
        return 'Presence Engine';
    }

    public function sortOrder(): int
    {
        return 20;
    }

    public function probe(): PlatformHealthComponent
    {
        $checkedAt = now();
        $awayTimeout = max(1, (int) config('presence.away_timeout_minutes', 15));
        $staleCount = WorkSession::query()
            ->whereNull('logout_at')
            ->where('last_activity_at', '<=', $checkedAt->copy()->subMinutes($awayTimeout))
            ->count();
        $lastRunAt = PlatformHealthCache::presenceLastTimeoutRunAt();
        $processed = PlatformHealthCache::presenceLastTimeoutProcessed();

        $status = PlatformHealthStatus::Healthy;
        $detail = 'Presence timeout pipeline is healthy.';

        if ($staleCount > 0) {
            $status = PlatformHealthStatus::Critical;
            $detail = "{$staleCount} stale open work session(s) exceed the away timeout.";
        } elseif ($lastRunAt === null) {
            $status = PlatformHealthStatus::Critical;
            $detail = 'No presence timeout run recorded. Confirm presence:process-timeouts is scheduled.';
        } else {
            $minutesAgo = (int) $lastRunAt->diffInMinutes($checkedAt);

            if ($minutesAgo > 10) {
                $status = PlatformHealthStatus::Critical;
                $detail = "Last presence timeout run was {$minutesAgo} minutes ago.";
            } elseif ($minutesAgo >= 3) {
                $status = PlatformHealthStatus::Warning;
                $detail = "Last presence timeout run was {$minutesAgo} minutes ago.";
            }
        }

        return new PlatformHealthComponent(
            key: $this->key(),
            label: $this->label(),
            status: $status,
            detail: $detail,
            checkedAt: $checkedAt,
            metrics: [
                'stale_sessions' => $staleCount,
                'last_timeout_run_at' => $lastRunAt?->toIso8601String(),
                'last_timeout_processed' => $processed,
                'away_timeout_minutes' => $awayTimeout,
            ],
        );
    }
}
