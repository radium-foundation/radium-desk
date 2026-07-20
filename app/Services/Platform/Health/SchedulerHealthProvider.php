<?php

namespace App\Services\Platform\Health;

use App\Contracts\Platform\PlatformHealthProvider;
use App\Data\Platform\PlatformHealthComponent;
use App\Enums\PlatformHealthStatus;
use App\Services\Platform\PlatformHealthCache;

class SchedulerHealthProvider implements PlatformHealthProvider
{
    public function key(): string
    {
        return 'scheduler';
    }

    public function label(): string
    {
        return 'Scheduler';
    }

    public function sortOrder(): int
    {
        return 10;
    }

    public function probe(): PlatformHealthComponent
    {
        $checkedAt = now();
        $lastRunAt = PlatformHealthCache::schedulerLastRunAt();

        if ($lastRunAt === null) {
            return new PlatformHealthComponent(
                key: $this->key(),
                label: $this->label(),
                status: PlatformHealthStatus::Critical,
                detail: 'No scheduler heartbeat recorded. Confirm cron is running schedule:run every minute.',
                checkedAt: $checkedAt,
                metrics: [
                    'last_run_at' => null,
                ],
            );
        }

        $minutesAgo = (int) $lastRunAt->diffInMinutes($checkedAt);

        if ($minutesAgo > 10) {
            $status = PlatformHealthStatus::Critical;
            $detail = "Last scheduler heartbeat was {$minutesAgo} minutes ago.";
        } elseif ($minutesAgo >= 3) {
            $status = PlatformHealthStatus::Warning;
            $detail = "Last scheduler heartbeat was {$minutesAgo} minutes ago.";
        } else {
            $status = PlatformHealthStatus::Healthy;
            $detail = 'Laravel scheduler heartbeat is fresh.';
        }

        return new PlatformHealthComponent(
            key: $this->key(),
            label: $this->label(),
            status: $status,
            detail: $detail,
            checkedAt: $checkedAt,
            metrics: [
                'last_run_at' => $lastRunAt->toIso8601String(),
                'minutes_ago' => $minutesAgo,
            ],
        );
    }
}
