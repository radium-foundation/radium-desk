<?php

namespace App\Services;

use App\Data\AutomationOperationsDashboardData;
use Illuminate\Support\Facades\Cache;

class AutomationOperationsSnapshotService
{
    public const CACHE_KEY = 'automation.operations.snapshot';

    public const TTL_SECONDS = 60;

    public function __construct(
        private readonly AutomationOperationsSnapshotBuilder $builder,
    ) {}

    public function get(): AutomationOperationsDashboardData
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return AutomationOperationsDashboardData::fromCacheArray($cached);
        }

        return $this->refresh();
    }

    public function refresh(): AutomationOperationsDashboardData
    {
        $snapshot = $this->builder->build();

        Cache::put(self::CACHE_KEY, $snapshot->toCacheArray(), self::TTL_SECONDS);

        return $snapshot;
    }
}
