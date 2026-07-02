<?php

namespace App\Data\Knowledge;

readonly class KnowledgeResponseDTO
{
    public function __construct(
        public CustomerKnowledgeDTO $customer,
        public DeviceKnowledgeDTO $device,
        public RepairKnowledgeDTO $repair,
        public BusinessKnowledgeDTO $business,
        public OperationsKnowledgeDTO $operations,
        public string $knowledgeSummary,
    ) {}

    public function similarRepairsCount(): int
    {
        return $this->repair->similarIncidentCount;
    }

    public function commonResolution(): ?string
    {
        return $this->repair->mostCommonResolution;
    }

    public function averageResolutionTimeDays(): ?float
    {
        return $this->repair->averageResolutionTimeDays;
    }

    public function repeatFailurePercent(): float
    {
        return $this->repair->repeatFailurePercentage;
    }

    public function historicalSuccessRate(): float
    {
        return $this->repair->historicalSuccessRate;
    }

    /**
     * @return list<string>
     */
    public function topRecommendedFixes(): array
    {
        return $this->repair->topRecommendedFixes;
    }

    public function previousEngineer(): ?string
    {
        return $this->repair->previousTechnician;
    }
}
