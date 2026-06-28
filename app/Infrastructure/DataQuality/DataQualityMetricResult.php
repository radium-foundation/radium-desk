<?php

namespace App\Infrastructure\DataQuality;

/**
 * Result of a single data quality metric evaluation.
 */
readonly class DataQualityMetricResult
{
    /**
     * @param  list<int>  $orderIds
     */
    public function __construct(
        public DataQualityMetric $metric,
        public int $count,
        public array $orderIds = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'metric' => $this->metric->value,
            'count' => $this->count,
            'order_ids' => $this->orderIds,
        ];
    }
}
