<?php

namespace App\Data\Operations;

readonly class IraMorningBriefing
{
    /**
     * @param  list<string>  $highlights
     * @param  list<IraOperationalRisk>  $risks
     * @param  list<IraOperationalRecommendation>  $recommendations
     */
    public function __construct(
        public string $greeting,
        public string $summary,
        public string $healthStatus,
        public array $highlights,
        public array $risks,
        public array $recommendations,
        public IraOperationalSnapshotData $snapshot,
        public ?IraOperationalSnapshotData $yesterdaySnapshot = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'greeting' => $this->greeting,
            'summary' => $this->summary,
            'health_status' => $this->healthStatus,
            'highlights' => $this->highlights,
            'risks' => array_map(fn (IraOperationalRisk $risk): array => $risk->toArray(), $this->risks),
            'recommendations' => array_map(
                fn (IraOperationalRecommendation $recommendation): array => $recommendation->toArray(),
                $this->recommendations,
            ),
            'snapshot' => $this->snapshot->toArray(),
            'yesterday_snapshot' => $this->yesterdaySnapshot?->toArray(),
        ];
    }
}
