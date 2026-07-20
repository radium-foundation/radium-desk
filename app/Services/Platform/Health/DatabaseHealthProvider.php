<?php

namespace App\Services\Platform\Health;

use App\Contracts\Platform\PlatformHealthProvider;
use App\Data\Platform\PlatformHealthComponent;
use App\Enums\PlatformHealthStatus;
use Illuminate\Support\Facades\DB;
use Throwable;

class DatabaseHealthProvider implements PlatformHealthProvider
{
    public function key(): string
    {
        return 'database';
    }

    public function label(): string
    {
        return 'Database';
    }

    public function sortOrder(): int
    {
        return 50;
    }

    public function probe(): PlatformHealthComponent
    {
        $checkedAt = now();

        try {
            $started = hrtime(true);
            DB::select('select 1');
            $elapsedMs = (hrtime(true) - $started) / 1_000_000;

            if ($elapsedMs >= 200) {
                $status = PlatformHealthStatus::Warning;
                $detail = sprintf('Database responded in %.0f ms.', $elapsedMs);
            } else {
                $status = PlatformHealthStatus::Healthy;
                $detail = sprintf('Database connection is healthy (%.0f ms).', $elapsedMs);
            }

            return new PlatformHealthComponent(
                key: $this->key(),
                label: $this->label(),
                status: $status,
                detail: $detail,
                checkedAt: $checkedAt,
                metrics: [
                    'response_time_ms' => round($elapsedMs, 1),
                ],
            );
        } catch (Throwable $exception) {
            return new PlatformHealthComponent(
                key: $this->key(),
                label: $this->label(),
                status: PlatformHealthStatus::Critical,
                detail: 'Database connection failed: '.$exception->getMessage(),
                checkedAt: $checkedAt,
            );
        }
    }
}
