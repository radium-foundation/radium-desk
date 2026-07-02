<?php

namespace App\Data\AI;

use App\Data\Knowledge\KnowledgeResponseDTO;
use App\Enums\AI\AIConfidenceLevel;

readonly class AIResponseDTO
{
    /**
     * @param  list<AIRiskIndicatorDTO>  $riskIndicators
     * @param  list<AIRecommendationDTO>  $suggestedNextActions
     * @param  array{label: string, occurred_at: string|null}|null  $paymentStatus
     */
    public function __construct(
        public string $customerSummary,
        public string $incidentSummary,
        public string $warrantyStatus,
        public ?array $paymentStatus,
        public array $riskIndicators,
        public array $suggestedNextActions,
        public string $suggestedCustomerReply,
        public float $confidence,
        public AIConfidenceLevel $confidenceLevel,
        public int $confidenceScore,
        public string $classification,
        public string $estimatedResolution,
        public ?string $recommendationExplanation,
        public string $providerName,
        public CustomerIntelligenceDTO $customerIntelligence,
        public DeviceIntelligenceDTO $deviceIntelligence,
        public OperationalIntelligenceDTO $operationalIntelligence,
        public BusinessIntelligenceDTO $businessIntelligence,
        public KnowledgeResponseDTO $knowledge,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'customer_summary' => $this->customerSummary,
            'incident_summary' => $this->incidentSummary,
            'warranty_status' => $this->warrantyStatus,
            'payment_status' => $this->paymentStatus,
            'risk_indicators' => array_map(
                fn (AIRiskIndicatorDTO $indicator): array => [
                    'label' => $indicator->label,
                    'level' => $indicator->level->value,
                ],
                $this->riskIndicators,
            ),
            'suggested_next_actions' => array_map(
                fn (AIRecommendationDTO $action): array => [
                    'title' => $action->title,
                    'description' => $action->description,
                    'confidence' => $action->confidence,
                    'rationale' => $action->rationale,
                ],
                $this->suggestedNextActions,
            ),
            'suggested_customer_reply' => $this->suggestedCustomerReply,
            'confidence' => $this->confidence,
            'confidence_level' => $this->confidenceLevel->value,
            'confidence_score' => $this->confidenceScore,
            'classification' => $this->classification,
            'estimated_resolution' => $this->estimatedResolution,
            'recommendation_explanation' => $this->recommendationExplanation,
            'provider_name' => $this->providerName,
        ];
    }
}
