<?php

namespace App\Services\Executive\Snapshots;

use App\Data\Executive\ExecutiveMetricDto;
use App\Data\Executive\ExecutiveMetricsSnapshot;
use App\Enums\ExecutiveSnapshotGranularity;
use Illuminate\Support\Carbon;

class ExecutiveSnapshotWriter
{
    public function __construct(
        private readonly ExecutiveSnapshotRepository $repository,
    ) {}

    public function write(ExecutiveMetricsSnapshot $snapshot, ?Carbon $at = null): int
    {
        $bucket = ($at ?? now())->copy()->startOfHour();
        $createdAt = now();

        $rows = array_map(
            fn (ExecutiveMetricDto $metric): array => [
                'metric_key' => $metric->id,
                'snapshot_time' => $bucket,
                'metric_value' => is_numeric($metric->value) ? (float) $metric->value : 0.0,
                'status' => $metric->status->value,
                'granularity' => ExecutiveSnapshotGranularity::Hourly->value,
                'metadata' => [
                    'title' => $metric->title,
                    'period' => $metric->period->value,
                    'formatted_value' => $metric->formattedValue,
                    'icon' => $metric->icon,
                ],
                'created_at' => $createdAt,
            ],
            $snapshot->metrics,
        );

        return $this->repository->upsertHourlyBatch($rows);
    }
}
