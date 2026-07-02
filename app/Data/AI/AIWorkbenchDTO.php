<?php

namespace App\Data\AI;

use App\Enums\AI\AIConfidenceLevel;
use Illuminate\Support\Carbon;

readonly class AIWorkbenchDTO
{
    /**
     * @param  list<array{key: string, channel: string, channel_label: string, content: string, confidence: string, confidence_score: int, explanation: string}>  $customerReplies
     * @param  array{content: string, confidence: string, confidence_score: int, explanation: string}  $internalNote
     * @param  list<array{key: string, label: string, explanation: string}>  $checklist
     * @param  list<array{key: string, label: string, description: string, confidence: string, confidence_score: int, explanation: string}>  $workflowSuggestions
     */
    public function __construct(
        public int $incidentId,
        public string $scenario,
        public string $scenarioLabel,
        public array $customerReplies,
        public array $internalNote,
        public array $checklist,
        public array $workflowSuggestions,
        public AIConfidenceLevel $confidenceLevel,
        public int $confidenceScore,
        public string $confidenceExplanation,
        public string $providerName,
        public Carbon $generatedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'incident_id' => $this->incidentId,
            'scenario' => $this->scenario,
            'scenario_label' => $this->scenarioLabel,
            'customer_replies' => $this->customerReplies,
            'internal_note' => $this->internalNote,
            'checklist' => $this->checklist,
            'workflow_suggestions' => $this->workflowSuggestions,
            'confidence_level' => $this->confidenceLevel->value,
            'confidence_score' => $this->confidenceScore,
            'confidence_explanation' => $this->confidenceExplanation,
            'provider_name' => $this->providerName,
            'generated_at' => $this->generatedAt->toIso8601String(),
        ];
    }
}
