<?php

namespace App\Services\Customer360\Intelligence;

use App\Contracts\Customer360\CaseIntelligenceLanguageEnhancer;
use App\Data\AI\IRAExecutiveSummaryDTO;
use App\Data\Customer360\Intelligence\CaseIntelligenceSnapshot;
use App\Models\Incident;
use App\Services\AI\AIService;
use App\Services\Customer360\Intelligence\Builders\CaseEvidenceBuilder;
use App\Services\Customer360\Intelligence\Builders\CaseIntelligenceFactCollector;
use App\Services\Customer360\Intelligence\Builders\CaseRecommendationBuilder;
use App\Services\Customer360\Intelligence\Builders\CaseRiskBuilder;
use App\Services\Customer360\Intelligence\Builders\CaseStateBuilder;
use App\Services\Customer360\Intelligence\Builders\CaseSummaryBuilder;
use App\Services\Operations\OperationsAdvisorService;

/**
 * Customer 360 case intelligence orchestration layer.
 *
 * Owns assembly of CaseIntelligenceSnapshot from deterministic builders.
 * Contains no SQL and does not invent business facts.
 *
 * Future LLM providers plug in via CaseIntelligenceLanguageEnhancer and
 * receive only the completed snapshot (never raw timeline).
 */
class CaseIntelligenceEngine
{
    public function __construct(
        private readonly CaseIntelligenceFactCollector $factCollector,
        private readonly CaseStateBuilder $stateBuilder,
        private readonly CaseRiskBuilder $riskBuilder,
        private readonly CaseEvidenceBuilder $evidenceBuilder,
        private readonly CaseRecommendationBuilder $recommendationBuilder,
        private readonly CaseSummaryBuilder $summaryBuilder,
        private readonly AIService $aiService,
        private readonly OperationsAdvisorService $operationsAdvisorService,
        private readonly CaseIntelligenceLanguageEnhancer $languageEnhancer,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('ira.case_intelligence_engine.enabled', false);
    }

    public function build(Incident $incident): ?CaseIntelligenceSnapshot
    {
        $facts = $this->factCollector->collect($incident);

        if ($facts === null) {
            return null;
        }

        $aiBundle = $this->aiService->buildBundle(
            $facts->incident,
            $facts->buildSnapshot,
            $facts->scopeCache,
        );
        $operationsAdvisorInsights = $this->operationsAdvisorService->incidentInsightsFromBundle(
            $facts->incident,
            $aiBundle,
            $facts->buildSnapshot,
        );

        $state = $this->stateBuilder->build($facts, $aiBundle);
        $riskProjection = $this->riskBuilder->build($aiBundle, $operationsAdvisorInsights);
        $evidence = $this->evidenceBuilder->build($facts, $aiBundle);
        $executiveSummary = $this->summaryBuilder->build(
            $facts->incident,
            $aiBundle,
            $facts,
            $operationsAdvisorInsights,
        );
        $recommendedAction = $this->recommendationBuilder->build(
            $facts,
            $aiBundle,
            $executiveSummary,
        );

        $snapshot = new CaseIntelligenceSnapshot(
            incidentId: $facts->incident->id,
            orderId: $facts->order->id,
            generatedAt: now(),
            schemaVersion: CaseIntelligenceSnapshot::SCHEMA_VERSION,
            currentStatusCode: $state['current_status_code'],
            currentStatusLabel: $state['current_status_label'],
            slaStatus: $state['sla_status'],
            isWaiting: $state['is_waiting'],
            waitingParty: $state['waiting_party'],
            waitingReasonCode: $state['waiting_reason_code'],
            waitingReasonLabel: $state['waiting_reason_label'],
            waitingSince: $state['waiting_since'],
            blockers: $state['blockers'],
            journey: $facts->customerJourney,
            supportAppointment: $facts->supportAppointment,
            engineerUserId: $facts->incident->assigned_to_user_id,
            engineerName: $facts->incident->assignee?->name,
            lastPayment: $aiBundle->context->lastPayment,
            serialMissing: $aiBundle->context->serialMissing,
            customerSummary: $facts->customerSummary,
            activeServices: $facts->activeServices,
            enrichmentMetadata: $facts->enrichmentMetadata,
            waitingStateCard: $facts->waitingStateCard,
            timeline: $facts->timeline,
            risks: $riskProjection['risks'],
            priorityLevel: $state['priority_level'],
            priorityDrivers: $state['priority_drivers'],
            recommendedAction: $recommendedAction,
            executiveSummary: $executiveSummary,
            evidence: $evidence,
            confidenceLevel: $aiBundle->response->confidenceLevel,
            confidenceScore: $aiBundle->response->confidenceScore,
            openQuestions: $state['open_questions'],
            supervisorInsights: $riskProjection['supervisor_insights'],
            customerMoodLevel: 'unknown',
            aiBundle: $aiBundle,
            context: $aiBundle->context,
            operationsAdvisorInsights: $operationsAdvisorInsights,
        );

        return $this->languageEnhancer->enhance($snapshot);
    }

    public function executiveSummary(Incident $incident): ?IRAExecutiveSummaryDTO
    {
        return $this->build($incident)?->executiveSummary;
    }
}
