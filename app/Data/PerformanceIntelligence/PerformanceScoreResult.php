<?php

namespace App\Data\PerformanceIntelligence;

readonly class PerformanceScoreResult
{
    /**
     * @param  array<string, mixed>  $breakdown
     * @param  array<string, list<string>>  $explanations
     * @param  array<string, mixed>  $featureFlags
     */
    public function __construct(
        public int $userId,
        public string $workDate,
        public string $version,
        public int $outcomeScore,
        public int $reachScore,
        public int $contributionScore,
        public int $commitmentScore,
        public int $qualityScore,
        public float $compositeScore,
        public array $breakdown,
        public PerformanceDayInputs $inputs,
        public array $explanations,
        public array $featureFlags,
        public int $calculationDurationMs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSnapshotAttributes(): array
    {
        return [
            'user_id' => $this->userId,
            'snapshot_date' => $this->workDate,
            'version' => $this->version,
            'outcome_score' => $this->outcomeScore,
            'reach_score' => $this->reachScore,
            'contribution_score' => $this->contributionScore,
            'commitment_score' => $this->commitmentScore,
            'quality_score' => $this->qualityScore,
            'composite_score' => $this->compositeScore,
            'breakdown' => $this->breakdown,
            'inputs' => $this->inputs->toArray(),
            'explanations' => $this->explanations,
            'feature_flags' => $this->featureFlags,
            'calculation_duration_ms' => $this->calculationDurationMs,
            'calculated_at' => now(),
        ];
    }
}
