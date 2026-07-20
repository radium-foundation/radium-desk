<?php

namespace App\Services\Executive\Insights;

use App\Data\Executive\ExecutiveInsight;
use App\Data\Executive\ExecutiveMetricDto;
use App\Data\Executive\ExecutiveMetricsSnapshot;
use App\Enums\ExecutiveMetricPolarity;
use App\Enums\ExecutiveTrendDirection;
use App\Enums\PlatformHealthStatus;

class ExecutiveInsightEngine
{
    /**
     * @return list<ExecutiveInsight>
     */
    public function analyze(ExecutiveMetricsSnapshot $snapshot): array
    {
        $insights = [];

        foreach ($snapshot->metrics as $metric) {
            foreach ($this->insightsFor($metric) as $insight) {
                $insights[] = $insight;
            }
        }

        usort(
            $insights,
            fn (ExecutiveInsight $a, ExecutiveInsight $b): int => $b->severity->severity() <=> $a->severity->severity(),
        );

        $max = (int) config('executive_metrics.insight.max_insights', 5);

        return array_slice($insights, 0, max(0, $max));
    }

    /**
     * @return list<ExecutiveInsight>
     */
    private function insightsFor(ExecutiveMetricDto $metric): array
    {
        $insights = [];
        $minPercent = (float) config('executive_metrics.insight.min_percent', 10.0);
        $band = (float) config('executive_metrics.insight.weekly_average_band', 0.15);
        $polarity = $this->polarity($metric->id);
        $percent = $metric->trendPercentage;
        $direction = $metric->trendDirection;
        $abs = $percent !== null ? abs($percent) : null;

        if (
            $percent !== null
            && $direction !== null
            && $abs !== null
            && $abs >= $minPercent
            && in_array($direction, [ExecutiveTrendDirection::Positive, ExecutiveTrendDirection::Negative], true)
        ) {
            $rounded = (int) round($abs);

            if ($polarity === ExecutiveMetricPolarity::LowerBetter) {
                if ($direction === ExecutiveTrendDirection::Negative) {
                    $insights[] = $this->make(
                        metric: $metric,
                        code: $metric->id.'_up',
                        message: "{$metric->title} increased {$rounded}%.",
                        severity: PlatformHealthStatus::Warning,
                    );
                } else {
                    $insights[] = $this->make(
                        metric: $metric,
                        code: $metric->id.'_improved',
                        message: "{$metric->title} improved {$rounded}%.",
                        severity: PlatformHealthStatus::Healthy,
                    );
                }
            }

            if ($polarity === ExecutiveMetricPolarity::HigherBetter) {
                if ($direction === ExecutiveTrendDirection::Negative) {
                    $insights[] = $this->make(
                        metric: $metric,
                        code: $metric->id.'_down',
                        message: "{$metric->title} decreased {$rounded}%.",
                        severity: PlatformHealthStatus::Warning,
                    );
                } else {
                    $insights[] = $this->make(
                        metric: $metric,
                        code: $metric->id.'_up',
                        message: "{$metric->title} increased {$rounded}%.",
                        severity: PlatformHealthStatus::Healthy,
                    );
                }
            }
        }

        $current = $metric->currentValue ?? (is_numeric($metric->value) ? (float) $metric->value : null);
        $weekly = $metric->sevenDayAverage;

        if ($current !== null && $weekly !== null && $weekly > 0) {
            if ($polarity === ExecutiveMetricPolarity::HigherBetter && $current < ($weekly * (1 - $band))) {
                $insights[] = $this->make(
                    metric: $metric,
                    code: $metric->id.'_below_weekly_average',
                    message: "{$metric->title} are below weekly average.",
                    severity: PlatformHealthStatus::Warning,
                );
            }

            if ($polarity === ExecutiveMetricPolarity::LowerBetter && $current > ($weekly * (1 + $band))) {
                $insights[] = $this->make(
                    metric: $metric,
                    code: $metric->id.'_above_weekly_average',
                    message: "{$metric->title} are above weekly average.",
                    severity: PlatformHealthStatus::Warning,
                );
            }
        }

        return $insights;
    }

    private function make(
        ExecutiveMetricDto $metric,
        string $code,
        string $message,
        PlatformHealthStatus $severity,
    ): ExecutiveInsight {
        return new ExecutiveInsight(
            id: $code,
            metricId: $metric->id,
            code: $code,
            message: $message,
            severity: $severity,
            meta: [
                'trend_percentage' => $metric->trendPercentage,
                'trend_direction' => $metric->trendDirection?->value,
                'current_value' => $metric->currentValue ?? $metric->value,
                'previous_value' => $metric->previousValue,
                'seven_day_average' => $metric->sevenDayAverage,
            ],
        );
    }

    private function polarity(string $metricKey): ExecutiveMetricPolarity
    {
        $raw = config("executive_metrics.polarity.{$metricKey}", 'neutral');

        return ExecutiveMetricPolarity::tryFrom((string) $raw) ?? ExecutiveMetricPolarity::Neutral;
    }
}
