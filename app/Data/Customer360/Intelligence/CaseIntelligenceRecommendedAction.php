<?php

namespace App\Data\Customer360\Intelligence;

readonly class CaseIntelligenceRecommendedAction
{
    /**
     * @param  list<string>  $rationale
     * @param  list<string>  $secondaryActionKeys
     */
    public function __construct(
        public string $actionKey,
        public string $label,
        public array $rationale,
        public string $confidence,
        public ?string $matchedRuleId = null,
        public array $secondaryActionKeys = [],
        public ?string $recommendationText = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action_key' => $this->actionKey,
            'label' => $this->label,
            'rationale' => $this->rationale,
            'confidence' => $this->confidence,
            'matched_rule_id' => $this->matchedRuleId,
            'secondary_action_keys' => $this->secondaryActionKeys,
            'recommendation_text' => $this->recommendationText,
        ];
    }
}
