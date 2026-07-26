<?php

namespace App\Services\Customer360\Intelligence\Builders;

use App\Data\AI\AIIncidentBundle;
use App\Data\AI\IRAExecutiveSummaryDTO;
use App\Data\Customer360\Intelligence\CaseIntelligenceFacts;
use App\Data\Customer360\Intelligence\CaseIntelligenceRecommendedAction;
use App\Data\Operations\OperationsInsightDTO;

/**
 * Owns recommended_action / secondary_actions for the snapshot.
 * Delegates advisor rule priority to CaseAdvisorDecisionBuilder.
 */
class CaseRecommendationBuilder
{
    public function __construct(
        private readonly CaseAdvisorDecisionBuilder $advisorDecisionBuilder,
    ) {}

    /**
     * @param  list<OperationsInsightDTO>  $operationsAdvisorInsights
     * @param  array<string, mixed>  $healthCardViewModel
     * @param  array<string, bool>  $actionVisibility
     * @return array{
     *     recommended_action: CaseIntelligenceRecommendedAction,
     *     advisor_view_model: array<string, mixed>|null,
     * }
     */
    public function build(
        CaseIntelligenceFacts $facts,
        AIIncidentBundle $bundle,
        IRAExecutiveSummaryDTO $executiveSummary,
        array $operationsAdvisorInsights,
        array $healthCardViewModel,
        array $actionVisibility,
        bool $canEscalate,
    ): array {
        $advisorViewModel = $this->advisorDecisionBuilder->decide([
            'incident' => $facts->incident,
            'order' => $facts->order,
            'customerSummary' => $facts->customerSummary,
            'healthCardViewModel' => $healthCardViewModel,
            'waitingStateCard' => $facts->waitingStateCard,
            'supportAppointment' => $facts->supportAppointment,
            'customerJourney' => $facts->customerJourney,
            'operationsAdvisorInsights' => $operationsAdvisorInsights,
            'actionVisibility' => $actionVisibility,
            'canEscalate' => $canEscalate,
        ]);

        if ($advisorViewModel === null) {
            $fallbackText = filled($executiveSummary->opinion)
                ? $executiveSummary->opinion
                : 'Contact the customer to advance this case.';

            return [
                'recommended_action' => new CaseIntelligenceRecommendedAction(
                    actionKey: 'contact_customer',
                    label: 'Contact Customer',
                    rationale: [$fallbackText],
                    confidence: 'low',
                    matchedRuleId: 'inactive_or_unavailable',
                    secondaryActionKeys: [],
                    secondaryActions: [],
                    recommendationText: $fallbackText,
                    priority: 0,
                    signals: [],
                ),
                'advisor_view_model' => null,
            ];
        }

        $action = $advisorViewModel['recommended_action'];
        $secondaryActions = is_array($advisorViewModel['secondary_actions'] ?? null)
            ? $advisorViewModel['secondary_actions']
            : [];
        $ruleContext = is_array($advisorViewModel['rule_context'] ?? null)
            ? $advisorViewModel['rule_context']
            : [];
        $rationale = is_array($advisorViewModel['reasons'] ?? null) ? $advisorViewModel['reasons'] : [];
        $label = (string) ($action['label'] ?? 'Contact Customer');
        $recommendationText = $this->canonicalRecommendationText($label, $rationale);

        $recommendedAction = new CaseIntelligenceRecommendedAction(
            actionKey: (string) ($action['key'] ?? 'contact_customer'),
            label: $label,
            rationale: $rationale,
            confidence: (string) ($advisorViewModel['confidence']['level'] ?? 'medium'),
            matchedRuleId: isset($ruleContext['matched_rule']) ? (string) $ruleContext['matched_rule'] : null,
            secondaryActionKeys: array_values(array_map(
                fn (array $item): string => (string) ($item['key'] ?? ''),
                $secondaryActions,
            )),
            secondaryActions: $secondaryActions,
            recommendationText: $recommendationText,
            priority: (int) ($ruleContext['priority'] ?? 0),
            signals: is_array($ruleContext['signals'] ?? null) ? $ruleContext['signals'] : [],
        );

        // Keep advisor view model aligned with the same canonical recommendation (Q2).
        $advisorViewModel['recommended_action']['recommendation_text'] = $recommendationText;
        $advisorViewModel['recommendation_text'] = $recommendationText;

        return [
            'recommended_action' => $recommendedAction,
            'advisor_view_model' => $advisorViewModel,
        ];
    }

    /**
     * @param  list<string>  $rationale
     */
    private function canonicalRecommendationText(string $label, array $rationale): string
    {
        $primary = trim((string) ($rationale[0] ?? ''));

        if ($primary !== '') {
            return $primary;
        }

        return 'Next action: '.$label.'.';
    }
}
