<?php

namespace App\Data\Operations;

use App\Enums\AI\AIConfidenceLevel;
use App\Enums\AI\AIRiskLevel;
use App\Enums\Operations\OperationsInsightCategory;

readonly class OperationsInsightDTO
{
    /**
     * @param  list<array<string, mixed>>  $affectedIncidents
     * @param  list<array<string, mixed>>  $affectedCustomers
     * @param  array<string, mixed>  $supportingMetrics
     */
    public function __construct(
        public string $title,
        public OperationsInsightCategory $category,
        public AIRiskLevel $severity,
        public AIConfidenceLevel $confidence,
        public int $confidenceScore,
        public string $recommendation,
        public array $affectedIncidents = [],
        public array $affectedCustomers = [],
        public array $supportingMetrics = [],
        public ?string $actionUrl = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'severity' => $this->severity->value,
            'severity_label' => $this->severity->label(),
            'confidence' => $this->confidence->value,
            'confidence_label' => $this->confidence->label(),
            'confidence_score' => $this->confidenceScore,
            'recommendation' => $this->recommendation,
            'affected_incidents' => $this->affectedIncidents,
            'affected_customers' => $this->affectedCustomers,
            'supporting_metrics' => $this->supportingMetrics,
            'action_url' => $this->actionUrl,
        ];
    }
}
