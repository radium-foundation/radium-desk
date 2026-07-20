<?php

namespace App\Services\Executive\Snapshots;

use App\Enums\ExecutiveSnapshotGranularity;
use App\Models\ExecutiveMetricSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ExecutiveSnapshotRepository
{
    /**
     * @param  list<array{
     *     metric_key: string,
     *     snapshot_time: Carbon|string,
     *     metric_value: float|int,
     *     status: ?string,
     *     granularity: string,
     *     metadata: ?array,
     *     created_at: Carbon|string
     * }>  $rows
     */
    public function upsertHourlyBatch(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $payload = array_map(function (array $row): array {
            return [
                'metric_key' => $row['metric_key'],
                'snapshot_time' => Carbon::parse($row['snapshot_time'])->toDateTimeString(),
                'metric_value' => $row['metric_value'],
                'status' => $row['status'],
                'granularity' => $row['granularity'],
                'metadata' => isset($row['metadata']) ? json_encode($row['metadata']) : null,
                'created_at' => Carbon::parse($row['created_at'])->toDateTimeString(),
            ];
        }, $rows);

        ExecutiveMetricSnapshot::query()->upsert(
            $payload,
            ['metric_key', 'snapshot_time', 'granularity'],
            ['metric_value', 'status', 'metadata', 'created_at'],
        );

        return count($payload);
    }

    /**
     * @param  list<string>  $metricKeys
     * @return Collection<int, ExecutiveMetricSnapshot>
     */
    public function hourlyWindow(
        array $metricKeys,
        Carbon $from,
        Carbon $to,
    ): Collection {
        if ($metricKeys === []) {
            return collect();
        }

        return ExecutiveMetricSnapshot::query()
            ->whereIn('metric_key', $metricKeys)
            ->where('granularity', ExecutiveSnapshotGranularity::Hourly)
            ->whereBetween('snapshot_time', [$from, $to])
            ->orderBy('snapshot_time')
            ->get();
    }

    public function countAll(): int
    {
        return ExecutiveMetricSnapshot::query()->count();
    }
}
