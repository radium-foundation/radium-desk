<?php

namespace App\Data\Customer360\Intelligence;

/**
 * Structured case narrative for language renderers / future AI wording layers.
 * Not a free-form paragraph — sections of discrete facts.
 */
readonly class CaseStory
{
    /**
     * @param  list<string>  $currentSituation
     * @param  list<string>  $progress
     * @param  list<string>  $blockers
     * @param  list<string>  $risks
     * @param  list<string>  $recommendedAction
     * @param  list<string>  $supportingFacts
     */
    public function __construct(
        public array $currentSituation,
        public array $progress,
        public array $blockers,
        public array $risks,
        public array $recommendedAction,
        public array $supportingFacts,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        return [
            'current_situation' => $this->currentSituation,
            'progress' => $this->progress,
            'blockers' => $this->blockers,
            'risks' => $this->risks,
            'recommended_action' => $this->recommendedAction,
            'supporting_facts' => $this->supportingFacts,
        ];
    }
}
