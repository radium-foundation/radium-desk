<?php

namespace App\Data\Executive;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

readonly class ExecutiveMetricsSnapshot
{
    /**
     * @param  list<ExecutiveMetricDto>  $metrics
     */
    public function __construct(
        public ExecutiveMetricPeriod $period,
        public array $metrics,
        public Carbon $generatedAt,
    ) {}

    public function get(string $id): ExecutiveMetricDto
    {
        foreach ($this->metrics as $metric) {
            if ($metric->id === $id) {
                return $metric;
            }
        }

        throw new InvalidArgumentException("Unknown executive metric [{$id}].");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period->value,
            'generated_at' => $this->generatedAt->toIso8601String(),
            'metrics' => array_map(
                fn (ExecutiveMetricDto $metric): array => $metric->toArray(),
                $this->metrics,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $period = ExecutiveMetricPeriod::from((string) ($payload['period'] ?? ExecutiveMetricPeriod::Today->value));
        $generatedAt = Carbon::parse((string) ($payload['generated_at'] ?? now()->toIso8601String()));
        $metrics = [];

        foreach ($payload['metrics'] ?? [] as $metricPayload) {
            if (! is_array($metricPayload)) {
                continue;
            }

            $metrics[] = ExecutiveMetricDto::fromArray($metricPayload, $period, $generatedAt);
        }

        return new self(
            period: $period,
            metrics: $metrics,
            generatedAt: $generatedAt,
        );
    }
}
