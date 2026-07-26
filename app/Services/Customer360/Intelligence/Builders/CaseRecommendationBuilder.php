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
            return [
                'recommended_action' => new CaseIntelligenceRecommendedAction(
                    actionKey: 'contact_customer',
                    label: 'Contact Customer',
                    rationale: [$executiveSummary->opinion],
                    confidence: 'low',
                    matchedRuleId: 'inactive_or_unavailable',
                    secondaryActionKeys: [],
                    secondaryActions: [],
                    recommendationText: $executiveSummary->recommendation,
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

        return [
            'recommended_action' => new CaseIntelligenceRecommendedAction(
                actionKey: (string) ($action['key'] ?? 'contact_customer'),
                label: (string) ($action['label'] ?? 'Contact Customer'),
                rationale: is_array($advisorViewModel['reasons'] ?? null) ? $advisorViewModel['reasons'] : [],
                confidence: (string) ($advisorViewModel['confidence']['level'] ?? 'medium'),
                matchedRuleId: isset($ruleContext['matched_rule']) ? (string) $ruleContext['matched_rule'] : null,
                secondaryActionKeys: array_values(array_map(
                    fn (array $item): string => (string) ($item['key'] ?? ''),
                    $secondaryActions,
                )),
                secondaryActions: $secondaryActions,
                recommendationText: $executiveSummary->recommendation,
                priority: (int) ($ruleContext['priority'] ?? 0),
                signals: is_array($ruleContext['signals'] ?? null) ? $ruleContext['signals'] : [],
            ),
            'advisor_view_model' => $advisorViewModel,
        ];
    }
}
