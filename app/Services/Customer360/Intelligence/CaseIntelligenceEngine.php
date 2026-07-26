<?php

namespace App\Services\Customer360\Intelligence;

use App\Contracts\Customer360\CaseIntelligenceLanguageEnhancer;
use App\Data\AI\IRAExecutiveSummaryDTO;
use App\Data\Customer360\Intelligence\CaseIntelligenceSnapshot;
use App\Data\Customer360\Intelligence\CaseIntelligenceRecommendedAction;
use App\Models\Incident;
use App\Models\User;
use App\Services\AI\AIService;
use App\Services\AI\AIWorkbenchService;
use App\Services\Customer360\Customer360ActionVisibilityService;
use App\Services\Customer360\Intelligence\Builders\CaseEvidenceBuilder;
use App\Services\Customer360\Intelligence\Builders\CaseIntelligenceFactCollector;
use App\Services\Customer360\Intelligence\Builders\CaseRecommendationBuilder;
use App\Services\Customer360\Intelligence\Builders\CaseRiskBuilder;
use App\Services\Customer360\Intelligence\Builders\CaseStateBuilder;
use App\Services\Customer360\Intelligence\Builders\CaseSummaryBuilder;
use App\Services\Operations\OperationsAdvisorService;
use App\Services\ServiceCaseEscalationService;
use App\Support\Customer360\Customer360HealthCardPresenter;

/**
 * Customer 360 case intelligence orchestration layer.
 *
 * Owns assembly of CaseIntelligenceSnapshot from deterministic builders.
 * Request-scoped: build() is memoized per incident id for the request lifetime.
 */
class CaseIntelligenceEngine
{
    /** @var array<int, CaseIntelligenceSnapshot|null> */
    private array $snapshotCache = [];

    /** @var array<int, int> */
    private array $buildCounts = [];

    public function __construct(
        private readonly CaseIntelligenceFactCollector $factCollector,
        private readonly CaseStateBuilder $stateBuilder,
        private readonly CaseRiskBuilder $riskBuilder,
        private readonly CaseEvidenceBuilder $evidenceBuilder,
        private readonly CaseRecommendationBuilder $recommendationBuilder,
        private readonly CaseSummaryBuilder $summaryBuilder,
        private readonly AIService $aiService,
        private readonly AIWorkbenchService $workbenchService,
        private readonly OperationsAdvisorService $operationsAdvisorService,
        private readonly CaseIntelligenceLanguageEnhancer $languageEnhancer,
        private readonly CaseReasoningEngine $reasoningEngine,
        private readonly Customer360ActionVisibilityService $actionVisibilityService,
        private readonly ServiceCaseEscalationService $escalationService,
        private readonly Customer360HealthCardPresenter $healthCardPresenter,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('ira.case_intelligence_engine.enabled', false);
    }

    public function build(Incident $incident, bool $force = false): ?CaseIntelligenceSnapshot
    {
        $cacheKey = $incident->id;

        if (! $force && array_key_exists($cacheKey, $this->snapshotCache)) {
            return $this->snapshotCache[$cacheKey];
        }

        $this->buildCounts[$cacheKey] = ($this->buildCounts[$cacheKey] ?? 0) + 1;

        $facts = $this->factCollector->collect($incident);

        if ($facts === null) {
            return $this->snapshotCache[$cacheKey] = null;
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

        $user = auth()->user();
        $actionVisibility = $this->actionVisibilityService->forIncident($facts->incident, $user);
        $canEscalate = $user instanceof User
            && $this->escalationService->canEscalate($facts->incident, $user);
        $healthCardViewModel = $this->healthCardPresenter->present(
            [
                'active_service_cases' => $facts->customerSummary['open_cases'] ?? 0,
                'repeat_contact' => null,
                'last_whatsapp' => null,
                'last_email' => null,
                'last_call' => null,
            ],
            $facts->customerSummary,
            $facts->order->customer_phone,
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
        $recommendation = $this->recommendationBuilder->build(
            $facts,
            $aiBundle,
            $executiveSummary,
            $operationsAdvisorInsights,
            $healthCardViewModel,
            $actionVisibility,
            $canEscalate,
        );
        $recommendedAction = $recommendation['recommended_action'];
        // Single canonical recommendation — executive summary must not diverge (Q2).
        $executiveSummary = $this->withCanonicalRecommendation($executiveSummary, $recommendedAction);
        $workbench = $this->workbenchService->buildFromBundle($facts->incident, $aiBundle);

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
            workbench: $workbench,
            advisorViewModel: $recommendation['advisor_view_model'],
            evidenceViewItems: $this->evidenceBuilder->toViewItems($evidence),
            incidentCreatedAt: $facts->incident->created_at,
            incidentUpdatedAt: $facts->incident->updated_at,
        );

        $snapshot = $this->reasoningEngine->enrich($snapshot);

        return $this->snapshotCache[$cacheKey] = $this->languageEnhancer->enhance($snapshot);
    }

    public function executiveSummary(Incident $incident): ?IRAExecutiveSummaryDTO
    {
        return $this->build($incident)?->executiveSummary;
    }

    private function withCanonicalRecommendation(
        IRAExecutiveSummaryDTO $executiveSummary,
        CaseIntelligenceRecommendedAction $recommendedAction,
    ): IRAExecutiveSummaryDTO {
        $text = trim((string) ($recommendedAction->recommendationText ?? ''));
        if ($text === '') {
            $text = $recommendedAction->label;
        }

        return new IRAExecutiveSummaryDTO(
            executiveSummary: $executiveSummary->executiveSummary,
            opinion: $executiveSummary->opinion,
            recommendation: $text,
            serialInsight: $executiveSummary->serialInsight,
        );
    }

    public function forget(?Incident $incident = null): void
    {
        if ($incident === null) {
            $this->snapshotCache = [];

            return;
        }

        unset($this->snapshotCache[$incident->id]);
    }

    public function buildCountFor(Incident $incident): int
    {
        return $this->buildCounts[$incident->id] ?? 0;
    }
}
