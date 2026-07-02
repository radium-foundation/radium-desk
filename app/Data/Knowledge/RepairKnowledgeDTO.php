<?php

namespace App\Data\Knowledge;

readonly class RepairKnowledgeDTO
{
    /**
     * @param  list<string>  $commonFixes
     * @param  list<string>  $successfulResolutions
     * @param  list<string>  $repeatFailures
     * @param  array<string, int>  $modelWiseRepairStatistics
     * @param  list<string>  $topRecommendedFixes
     */
    public function __construct(
        public int $similarIncidentCount,
        public ?string $mostCommonResolution,
        public ?float $averageResolutionTimeDays,
        public float $historicalSuccessRate,
        public float $repeatFailurePercentage,
        public ?string $previousTechnician,
        public array $commonFixes,
        public array $successfulResolutions,
        public array $repeatFailures,
        public ?float $averageRepairDurationDays,
        public array $modelWiseRepairStatistics,
        public array $topRecommendedFixes,
    ) {}
}
