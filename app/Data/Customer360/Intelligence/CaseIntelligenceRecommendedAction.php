<?php

namespace App\Data\Customer360\Intelligence;

readonly class CaseIntelligenceRecommendedAction
{
    /**
     * @param  list<string>  $rationale
     * @param  list<string>  $secondaryActionKeys
     * @param  list<array{key: string, label: string, icon: string}>  $secondaryActions
     * @param  array<string, mixed>  $signals
     */
    public function __construct(
        public string $actionKey,
        public string $label,
        public array $rationale,
        public string $confidence,
        public ?string $matchedRuleId = null,
        public array $secondaryActionKeys = [],
        public array $secondaryActions = [],
        public ?string $recommendationText = null,
        public int $priority = 0,
        public array $signals = [],
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
            'secondary_actions' => $this->secondaryActions,
            'recommendation_text' => $this->recommendationText,
            'priority' => $this->priority,
            'signals' => $this->signals,
        ];
    }
}
