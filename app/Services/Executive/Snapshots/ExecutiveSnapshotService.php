<?php

namespace App\Services\Executive\Snapshots;

use App\Data\Executive\ExecutiveMetricsSnapshot;
use App\Enums\ExecutiveSnapshotGranularity;
use App\Models\ExecutiveMetricSnapshot;
use App\Services\Executive\ExecutiveMetricsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ExecutiveSnapshotService
{
    public function __construct(
        private readonly ExecutiveMetricsService $metricsService,
        private readonly ExecutiveSnapshotWriter $writer,
        private readonly ExecutiveSnapshotRepository $repository,
    ) {}

    /**
     * Capture live metrics into historical storage. Scheduler-only path.
     *
     * @return array{written: int, snapshot: ExecutiveMetricsSnapshot}
     */
    public function capture(?Carbon $at = null): array
    {
        $live = $this->metricsService->snapshot(force: true);
        $written = $this->writer->write($live, $at);

        return [
            'written' => $written,
            'snapshot' => $live,
        ];
    }

    public function latestFor(string $metricKey): ?ExecutiveMetricSnapshot
    {
        return ExecutiveMetricSnapshot::query()
            ->where('metric_key', $metricKey)
            ->where('granularity', ExecutiveSnapshotGranularity::Hourly)
            ->orderByDesc('snapshot_time')
            ->first();
    }

    /**
     * @param  list<string>  $metricKeys
     * @return Collection<int, ExecutiveMetricSnapshot>
     */
    public function hourlyHistory(array $metricKeys, Carbon $from, Carbon $to): Collection
    {
        return $this->repository->hourlyWindow($metricKeys, $from, $to);
    }
}
