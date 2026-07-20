<?php

namespace App\Data\Executive;

use App\Enums\ExecutiveTrendDirection;
use App\Enums\PlatformHealthStatus;
use Illuminate\Support\Carbon;

readonly class ExecutiveMetricDto
{
    /**
     * @param  array<string, mixed>|null  $trend
     * @param  array<string, mixed>|null  $comparison
     * @param  array<string, mixed>|null  $futureChartData
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>|null  $sparkline
     * @param  array<string, mixed>|null  $forecast
     */
    public function __construct(
        public string $id,
        public string $title,
        public int|float|string $value,
        public string $formattedValue,
        public PlatformHealthStatus $status,
        public ?string $subtitle,
        public Carbon $lastUpdated,
        public ?string $detailUrl,
        public string $icon,
        public ExecutiveMetricPeriod $period,
        public ?array $trend = null,
        public ?array $comparison = null,
        public ?array $futureChartData = null,
        public array $meta = [],
        public int|float|null $currentValue = null,
        public int|float|null $previousValue = null,
        public int|float|null $sevenDayAverage = null,
        public int|float|null $thirtyDayAverage = null,
        public ?float $trendPercentage = null,
        public ?ExecutiveTrendDirection $trendDirection = null,
        public ?string $comparisonLabel = null,
        public ?array $sparkline = null,
        public ?array $forecast = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'value' => $this->value,
            'formatted_value' => $this->formattedValue,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'subtitle' => $this->subtitle,
            'last_updated' => $this->lastUpdated->toIso8601String(),
            'detail_url' => $this->detailUrl,
            'icon' => $this->icon,
            'period' => $this->period->value,
            'trend' => $this->trend,
            'comparison' => $this->comparison,
            'future_chart_data' => $this->futureChartData,
            'meta' => $this->meta,
            'current_value' => $this->currentValue,
            'previous_value' => $this->previousValue,
            'seven_day_average' => $this->sevenDayAverage,
            'thirty_day_average' => $this->thirtyDayAverage,
            'trend_percentage' => $this->trendPercentage,
            'trend_direction' => $this->trendDirection?->value,
            'comparison_label' => $this->comparisonLabel,
            'sparkline' => $this->sparkline,
            'forecast' => $this->forecast,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload, ?ExecutiveMetricPeriod $period = null, ?Carbon $fallbackUpdated = null): self
    {
        $resolvedPeriod = $period ?? ExecutiveMetricPeriod::from(
            (string) ($payload['period'] ?? ExecutiveMetricPeriod::Today->value),
        );
        $lastUpdated = isset($payload['last_updated'])
            ? Carbon::parse((string) $payload['last_updated'])
            : ($fallbackUpdated?->copy() ?? now());

        $directionRaw = $payload['trend_direction'] ?? null;
        $direction = is_string($directionRaw)
            ? ExecutiveTrendDirection::tryFrom($directionRaw)
            : null;

        return new self(
            id: (string) ($payload['id'] ?? ''),
            title: (string) ($payload['title'] ?? ''),
            value: $payload['value'] ?? 0,
            formattedValue: (string) ($payload['formatted_value'] ?? '0'),
            status: PlatformHealthStatus::from((string) ($payload['status'] ?? 'healthy')),
            subtitle: isset($payload['subtitle']) ? (string) $payload['subtitle'] : null,
            lastUpdated: $lastUpdated,
            detailUrl: isset($payload['detail_url']) ? (string) $payload['detail_url'] : null,
            icon: (string) ($payload['icon'] ?? ''),
            period: $resolvedPeriod,
            trend: is_array($payload['trend'] ?? null) ? $payload['trend'] : null,
            comparison: is_array($payload['comparison'] ?? null) ? $payload['comparison'] : null,
            futureChartData: is_array($payload['future_chart_data'] ?? null) ? $payload['future_chart_data'] : null,
            meta: is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
            currentValue: isset($payload['current_value']) && is_numeric($payload['current_value'])
                ? $payload['current_value'] + 0
                : null,
            previousValue: isset($payload['previous_value']) && is_numeric($payload['previous_value'])
                ? $payload['previous_value'] + 0
                : null,
            sevenDayAverage: isset($payload['seven_day_average']) && is_numeric($payload['seven_day_average'])
                ? $payload['seven_day_average'] + 0
                : null,
            thirtyDayAverage: isset($payload['thirty_day_average']) && is_numeric($payload['thirty_day_average'])
                ? $payload['thirty_day_average'] + 0
                : null,
            trendPercentage: isset($payload['trend_percentage']) && is_numeric($payload['trend_percentage'])
                ? (float) $payload['trend_percentage']
                : null,
            trendDirection: $direction,
            comparisonLabel: isset($payload['comparison_label']) ? (string) $payload['comparison_label'] : null,
            sparkline: is_array($payload['sparkline'] ?? null) ? $payload['sparkline'] : null,
            forecast: is_array($payload['forecast'] ?? null) ? $payload['forecast'] : null,
        );
    }
}
