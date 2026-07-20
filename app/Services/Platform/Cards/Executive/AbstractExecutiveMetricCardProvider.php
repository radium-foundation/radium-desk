<?php

namespace App\Services\Platform\Cards\Executive;

use App\Contracts\Platform\PlatformCardProvider;
use App\Data\Executive\ExecutiveMetricDto;
use App\Data\Platform\PlatformCardDefinition;
use App\Data\Platform\PlatformCardPayload;
use App\Data\Platform\PlatformMetric;
use App\Enums\PlatformCardSize;
use App\Enums\PlatformDashboardSection;
use App\Models\User;
use App\Services\Executive\ExecutiveMetricsService;
use App\Services\Platform\Concerns\InteractsWithPlatformCardDefinition;

abstract class AbstractExecutiveMetricCardProvider implements PlatformCardProvider
{
    use InteractsWithPlatformCardDefinition;

    public function __construct(
        protected readonly ExecutiveMetricsService $metrics,
    ) {}

    abstract protected function metricId(): string;

    /** Stable intelligence-layer metric id (e.g. open_cases). */
    abstract protected function metricKey(): string;

    abstract protected function metricTitle(): string;

    abstract protected function metricIcon(): string;

    abstract protected function priority(): int;

    public function definition(): PlatformCardDefinition
    {
        return new PlatformCardDefinition(
            id: $this->metricId(),
            title: $this->metricTitle(),
            section: PlatformDashboardSection::Executive->value,
            priority: $this->priority(),
            icon: $this->metricIcon(),
            refreshable: true,
            expandable: false,
            permission: null,
            size: PlatformCardSize::XSmall,
            subtitle: null,
            bodyPartial: 'admin.platform.cards.executive-metric',
            detailUrl: null,
            estimatedRefreshCost: 'cheap',
        );
    }

    public function load(User $viewer): PlatformCardPayload
    {
        return $this->payloadFromMetric(force: false);
    }

    public function refresh(User $viewer): PlatformCardPayload
    {
        return $this->payloadFromMetric(force: true);
    }

    private function payloadFromMetric(bool $force): PlatformCardPayload
    {
        $dto = $this->metrics->get($this->metricKey(), force: $force);

        return $this->mapDtoToPayload($dto);
    }

    private function mapDtoToPayload(ExecutiveMetricDto $dto): PlatformCardPayload
    {
        $definition = $this->definition();
        $trendLabel = is_array($dto->trend) ? ($dto->trend['label'] ?? null) : null;

        return PlatformCardPayload::fromDefinition(
            definition: $definition,
            status: $dto->status,
            generatedAt: $dto->lastUpdated->copy(),
            metrics: [
                new PlatformMetric(
                    key: 'value',
                    label: $dto->title,
                    value: $dto->formattedValue,
                    detail: is_string($trendLabel) ? $trendLabel : null,
                    status: $dto->status,
                ),
            ],
            meta: [
                'value' => $dto->value,
                'formatted_value' => $dto->formattedValue,
                'trend' => is_string($trendLabel) ? $trendLabel : null,
                'trend_direction' => $dto->trendDirection?->value,
                'trend_percentage' => $dto->trendPercentage,
                'comparison_label' => $dto->comparisonLabel,
                'previous_value' => $dto->previousValue,
                'seven_day_average' => $dto->sevenDayAverage,
                'thirty_day_average' => $dto->thirtyDayAverage,
                'icon' => $dto->icon,
                'metric_id' => $dto->id,
                'period' => $dto->period->value,
            ],
            detailUrl: $dto->detailUrl,
        );
    }
}
