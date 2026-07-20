<?php

namespace App\Services\Executive\Trends;

use App\Data\Executive\ExecutiveMetricDto;
use App\Enums\ExecutiveMetricPolarity;
use App\Enums\ExecutiveTrendDirection;
use App\Models\ExecutiveMetricSnapshot;
use App\Services\Executive\Snapshots\ExecutiveSnapshotRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TrendService
{
    /** @var Collection<string, Collection<int, ExecutiveMetricSnapshot>>|null */
    private ?Collection $historyByMetric = null;

    public function __construct(
        private readonly ExecutiveSnapshotRepository $repository,
    ) {}

    /**
     * @param  list<ExecutiveMetricDto>  $metrics
     * @return list<ExecutiveMetricDto>
     */
    public function enrich(array $metrics): array
    {
        $this->historyByMetric = null;
        $keys = array_map(fn (ExecutiveMetricDto $metric): string => $metric->id, $metrics);
        $this->loadHistory($keys);

        return array_map(fn (ExecutiveMetricDto $metric): ExecutiveMetricDto => $this->enrichOne($metric), $metrics);
    }

    private function enrichOne(ExecutiveMetricDto $metric): ExecutiveMetricDto
    {
        $current = is_numeric($metric->value) ? (float) $metric->value : 0.0;
        $previous = $this->previousValue($metric->id);
        $sevenDayAverage = $this->averageOfDailyRepresentatives($metric->id, 7);
        $thirtyDayAverage = $this->averageOfDailyRepresentatives($metric->id, 30);
        $trendPercentage = $this->trendPercentage($current, $previous);
        $direction = $this->direction($metric->id, $current, $previous, $trendPercentage);
        $comparisonLabel = (string) config('executive_metrics.comparison_label', 'Compared to yesterday');
        $trend = $this->trendPayload($direction, $trendPercentage);

        return new ExecutiveMetricDto(
            id: $metric->id,
            title: $metric->title,
            value: $metric->value,
            formattedValue: $metric->formattedValue,
            status: $metric->status,
            subtitle: $metric->subtitle,
            lastUpdated: $metric->lastUpdated->copy(),
            detailUrl: $metric->detailUrl,
            icon: $metric->icon,
            period: $metric->period,
            trend: $trend,
            comparison: [
                'label' => $comparisonLabel,
                'value' => $previous,
            ],
            futureChartData: $metric->futureChartData,
            meta: $metric->meta,
            currentValue: $current,
            previousValue: $previous,
            sevenDayAverage: $sevenDayAverage,
            thirtyDayAverage: $thirtyDayAverage,
            trendPercentage: $trendPercentage,
            trendDirection: $direction,
            comparisonLabel: $comparisonLabel,
            sparkline: null,
            forecast: null,
        );
    }

    /**
     * @param  list<string>  $metricKeys
     */
    private function loadHistory(array $metricKeys): void
    {
        if ($this->historyByMetric !== null) {
            return;
        }

        $from = now()->subDays(31)->startOfDay();
        $to = now()->endOfDay();
        $rows = $this->repository->hourlyWindow($metricKeys, $from, $to);

        $this->historyByMetric = $rows->groupBy('metric_key');
    }

    private function rowsFor(string $metricKey): Collection
    {
        return $this->historyByMetric?->get($metricKey, collect()) ?? collect();
    }

    private function previousValue(string $metricKey): ?float
    {
        $yesterday = now()->subDay();
        $sameHour = $yesterday->copy()->startOfHour();
        $rows = $this->rowsFor($metricKey);

        $sameHourRow = $rows->first(
            fn (ExecutiveMetricSnapshot $row): bool => $row->snapshot_time?->equalTo($sameHour) ?? false,
        );

        if ($sameHourRow !== null) {
            return (float) $sameHourRow->metric_value;
        }

        $dayStart = $yesterday->copy()->startOfDay();
        $dayEnd = $yesterday->copy()->endOfDay();

        $lastOfDay = $rows
            ->filter(function (ExecutiveMetricSnapshot $row) use ($dayStart, $dayEnd): bool {
                $time = $row->snapshot_time;

                return $time !== null && $time->betweenIncluded($dayStart, $dayEnd);
            })
            ->sortByDesc(fn (ExecutiveMetricSnapshot $row) => $row->snapshot_time?->timestamp ?? 0)
            ->first();

        return $lastOfDay !== null ? (float) $lastOfDay->metric_value : null;
    }

    private function averageOfDailyRepresentatives(string $metricKey, int $days): ?float
    {
        $rows = $this->rowsFor($metricKey);
        if ($rows->isEmpty()) {
            return null;
        }

        $values = [];

        for ($offset = 1; $offset <= $days; $offset++) {
            $day = now()->subDays($offset);
            $dayStart = $day->copy()->startOfDay();
            $dayEnd = $day->copy()->endOfDay();

            $representative = $rows
                ->filter(function (ExecutiveMetricSnapshot $row) use ($dayStart, $dayEnd): bool {
                    $time = $row->snapshot_time;

                    return $time !== null && $time->betweenIncluded($dayStart, $dayEnd);
                })
                ->sortByDesc(fn (ExecutiveMetricSnapshot $row) => $row->snapshot_time?->timestamp ?? 0)
                ->first();

            if ($representative !== null) {
                $values[] = (float) $representative->metric_value;
            }
        }

        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 2);
    }

    private function trendPercentage(float $current, ?float $previous): ?float
    {
        if ($previous === null) {
            return null;
        }

        if ($previous == 0.0) {
            return $current == 0.0 ? 0.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function direction(
        string $metricKey,
        float $current,
        ?float $previous,
        ?float $trendPercentage,
    ): ExecutiveTrendDirection {
        if ($previous === null || $trendPercentage === null) {
            return ExecutiveTrendDirection::Unknown;
        }

        $threshold = (float) config('executive_metrics.neutral_threshold_percent', 1.0);

        if (abs($trendPercentage) < $threshold || ($current == 0.0 && $previous == 0.0)) {
            return ExecutiveTrendDirection::Neutral;
        }

        $polarity = $this->polarity($metricKey);
        $wentUp = $trendPercentage > 0;

        return match ($polarity) {
            ExecutiveMetricPolarity::HigherBetter => $wentUp
                ? ExecutiveTrendDirection::Positive
                : ExecutiveTrendDirection::Negative,
            ExecutiveMetricPolarity::LowerBetter => $wentUp
                ? ExecutiveTrendDirection::Negative
                : ExecutiveTrendDirection::Positive,
            ExecutiveMetricPolarity::Neutral => ExecutiveTrendDirection::Neutral,
        };
    }

    private function polarity(string $metricKey): ExecutiveMetricPolarity
    {
        $raw = config("executive_metrics.polarity.{$metricKey}", 'neutral');

        return ExecutiveMetricPolarity::tryFrom((string) $raw) ?? ExecutiveMetricPolarity::Neutral;
    }

    /**
     * @return array{direction: string, label: string, delta: float|null}|null
     */
    private function trendPayload(ExecutiveTrendDirection $direction, ?float $trendPercentage): ?array
    {
        if ($direction === ExecutiveTrendDirection::Unknown || $trendPercentage === null) {
            return null;
        }

        $abs = abs($trendPercentage);
        $arrow = $trendPercentage > 0 ? '▲' : ($trendPercentage < 0 ? '▼' : '•');

        return [
            'direction' => $direction->value,
            'label' => "{$arrow} {$abs}%",
            'delta' => $trendPercentage,
        ];
    }
}
