<?php

namespace App\Data\Customer360\Intelligence;

/**
 * Deterministic enrichment produced by CaseReasoningEngine.
 */
readonly class CaseReasoningResult
{
    /**
     * @param  list<CaseReasoningFinding>  $findings
     * @param  list<string>  $matchedRuleKeys
     * @param  array<string, string>  $riskExplanations  risk key → explanation
     * @param  array<string, string>  $blockerExplanations  blocker key → explanation
     * @param  list<string>  $recommendedActionReasoning
     * @param  list<string>  $executiveSummaryFacts
     */
    public function __construct(
        public array $findings,
        public array $matchedRuleKeys,
        public array $riskExplanations,
        public array $blockerExplanations,
        public array $recommendedActionReasoning,
        public array $executiveSummaryFacts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'findings' => array_map(
                fn (CaseReasoningFinding $finding): array => $finding->toArray(),
                $this->findings,
            ),
            'matched_rule_keys' => $this->matchedRuleKeys,
            'risk_explanations' => $this->riskExplanations,
            'blocker_explanations' => $this->blockerExplanations,
            'recommended_action_reasoning' => $this->recommendedActionReasoning,
            'executive_summary_facts' => $this->executiveSummaryFacts,
        ];
    }
}
