<?php

namespace App\Data\Customer360\Intelligence;

use App\Enums\AI\AIRiskLevel;

readonly class CaseIntelligenceRisk
{
    /**
     * @param  list<string>  $evidenceRefs
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $category,
        public AIRiskLevel $severity,
        public ?int $confidenceScore = null,
        public array $evidenceRefs = [],
        public string $source = 'deterministic',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'category' => $this->category,
            'severity' => $this->severity->value,
            'confidence_score' => $this->confidenceScore,
            'evidence_refs' => $this->evidenceRefs,
            'source' => $this->source,
        ];
    }
}
