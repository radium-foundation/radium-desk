<?php

namespace App\Data\Customer360\Intelligence;

use App\Enums\AI\AIRiskLevel;

readonly class CaseReasoningFinding
{
    /**
     * @param  array<string, mixed>  $signals
     * @param  list<string>  $evidenceRefs
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $category,
        public AIRiskLevel $severity,
        public string $explanation,
        public array $signals = [],
        public array $evidenceRefs = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'category' => $this->category,
            'severity' => $this->severity->value,
            'explanation' => $this->explanation,
            'signals' => $this->signals,
            'evidence_refs' => $this->evidenceRefs,
        ];
    }
}
