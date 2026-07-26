<?php

namespace App\Data\Customer360\Intelligence;

use App\Data\AI\AIContextDTO;
use App\Data\AI\AIIncidentBundle;
use App\Data\AI\AIWorkbenchDTO;
use App\Data\AI\CustomerJourneyDTO;
use App\Data\AI\IRAExecutiveSummaryDTO;
use App\Data\Operations\OperationsInsightDTO;
use App\Data\TimelineViewModel;
use App\Enums\AI\AIConfidenceLevel;
use Illuminate\Support\Carbon;

/**
 * Canonical Customer 360 case intelligence model.
 *
 * Business facts on this snapshot are authoritative.
 * Future LLM enhancers may improve language fields only — never facts.
 * Future AI providers should consume caseStory, not raw timeline/events.
 */
readonly class CaseIntelligenceSnapshot
{
    public const SCHEMA_VERSION = '1.3';

    /**
     * @param  array<string, int>  $customerSummary
     * @param  list<array{label: string, status: string, variant: string}>  $activeServices
     * @param  array<string, mixed>  $enrichmentMetadata
     * @param  array<string, mixed>|null  $waitingStateCard
     * @param  array<string, mixed>|null  $supportAppointment
     * @param  list<CaseIntelligenceBlocker>  $blockers
     * @param  list<CaseIntelligenceRisk>  $risks
     * @param  list<CaseIntelligenceEvidence>  $evidence
     * @param  list<OperationsInsightDTO>  $operationsAdvisorInsights
     * @param  list<string>  $priorityDrivers
     * @param  list<string>  $openQuestions
     * @param  list<string>  $supervisorInsights
     * @param  array<string, mixed>|null  $advisorViewModel
     * @param  list<array{title: string, source: string, tone: string}>  $evidenceViewItems
     */
    public function __construct(
        public int $incidentId,
        public ?int $orderId,
        public Carbon $generatedAt,
        public string $schemaVersion,
        public string $currentStatusCode,
        public string $currentStatusLabel,
        public string $slaStatus,
        public bool $isWaiting,
        public string $waitingParty,
        public ?string $waitingReasonCode,
        public ?string $waitingReasonLabel,
        public ?Carbon $waitingSince,
        public array $blockers,
        public ?CustomerJourneyDTO $journey,
        public ?array $supportAppointment,
        public ?int $engineerUserId,
        public ?string $engineerName,
        public ?array $lastPayment,
        public bool $serialMissing,
        public array $customerSummary,
        public array $activeServices,
        public array $enrichmentMetadata,
        public ?array $waitingStateCard,
        public ?TimelineViewModel $timeline,
        public array $risks,
        public string $priorityLevel,
        public array $priorityDrivers,
        public CaseIntelligenceRecommendedAction $recommendedAction,
        public IRAExecutiveSummaryDTO $executiveSummary,
        public array $evidence,
        public AIConfidenceLevel $confidenceLevel,
        public int $confidenceScore,
        public array $openQuestions,
        public array $supervisorInsights,
        public string $customerMoodLevel,
        public AIIncidentBundle $aiBundle,
        public AIContextDTO $context,
        public array $operationsAdvisorInsights,
        public AIWorkbenchDTO $workbench,
        public ?array $advisorViewModel = null,
        public array $evidenceViewItems = [],
        public ?CaseReasoningResult $reasoning = null,
        public ?CaseStory $caseStory = null,
        public ?Carbon $incidentCreatedAt = null,
        public ?Carbon $incidentUpdatedAt = null,
        public ?CommunicationSummary $communicationSummary = null,
    ) {}

    /**
     * Clone with deterministic reasoning enrichment applied.
     * Does not invent or alter the canonical recommendedAction (Q2).
     * Does not dump reasoning findings into the executive briefing narrative.
     */
    public function withReasoning(CaseReasoningResult $reasoning, CaseStory $caseStory): self
    {
        $canonicalRecommendation = (string) (
            $this->recommendedAction->recommendationText
            ?? $this->recommendedAction->label
        );

        $blockers = array_map(
            function (CaseIntelligenceBlocker $blocker) use ($reasoning): CaseIntelligenceBlocker {
                $explanation = $reasoning->blockerExplanations[$blocker->key] ?? null;

                if ($explanation === null || $blocker->explanation === $explanation) {
                    return $blocker;
                }

                return new CaseIntelligenceBlocker(
                    key: $blocker->key,
                    label: $blocker->label,
                    party: $blocker->party,
                    severity: $blocker->severity,
                    since: $blocker->since,
                    evidenceRefs: $blocker->evidenceRefs,
                    clearsWhen: $blocker->clearsWhen,
                    explanation: $explanation,
                );
            },
            $this->blockers,
        );

        $risks = array_map(
            function (CaseIntelligenceRisk $risk) use ($reasoning): CaseIntelligenceRisk {
                $explanation = $reasoning->riskExplanations[$risk->key] ?? null;

                if ($explanation === null || $risk->explanation === $explanation) {
                    return $risk;
                }

                return new CaseIntelligenceRisk(
                    key: $risk->key,
                    label: $risk->label,
                    category: $risk->category,
                    severity: $risk->severity,
                    confidenceScore: $risk->confidenceScore,
                    evidenceRefs: $risk->evidenceRefs,
                    source: $risk->source,
                    explanation: $explanation,
                );
            },
            $this->risks,
        );

        return new self(
            incidentId: $this->incidentId,
            orderId: $this->orderId,
            generatedAt: $this->generatedAt,
            schemaVersion: self::SCHEMA_VERSION,
            currentStatusCode: $this->currentStatusCode,
            currentStatusLabel: $this->currentStatusLabel,
            slaStatus: $this->slaStatus,
            isWaiting: $this->isWaiting,
            waitingParty: $this->waitingParty,
            waitingReasonCode: $this->waitingReasonCode,
            waitingReasonLabel: $this->waitingReasonLabel,
            waitingSince: $this->waitingSince,
            blockers: $blockers,
            journey: $this->journey,
            supportAppointment: $this->supportAppointment,
            engineerUserId: $this->engineerUserId,
            engineerName: $this->engineerName,
            lastPayment: $this->lastPayment,
            serialMissing: $this->serialMissing,
            customerSummary: $this->customerSummary,
            activeServices: $this->activeServices,
            enrichmentMetadata: $this->enrichmentMetadata,
            waitingStateCard: $this->waitingStateCard,
            timeline: $this->timeline,
            risks: $risks,
            priorityLevel: $this->priorityLevel,
            priorityDrivers: $this->priorityDrivers,
            recommendedAction: $this->recommendedAction,
            executiveSummary: new IRAExecutiveSummaryDTO(
                executiveSummary: $this->executiveSummary->executiveSummary,
                opinion: $this->executiveSummary->opinion,
                recommendation: $canonicalRecommendation,
                serialInsight: $this->executiveSummary->serialInsight,
            ),
            evidence: $this->evidence,
            confidenceLevel: $this->confidenceLevel,
            confidenceScore: $this->confidenceScore,
            openQuestions: $this->openQuestions,
            supervisorInsights: $this->supervisorInsights,
            customerMoodLevel: $this->customerMoodLevel,
            aiBundle: $this->aiBundle,
            context: $this->context,
            operationsAdvisorInsights: $this->operationsAdvisorInsights,
            workbench: $this->workbench,
            advisorViewModel: $this->advisorViewModel,
            evidenceViewItems: $this->evidenceViewItems,
            reasoning: $reasoning,
            caseStory: $caseStory,
            incidentCreatedAt: $this->incidentCreatedAt,
            incidentUpdatedAt: $this->incidentUpdatedAt,
            communicationSummary: $this->communicationSummary,
        );
    }

    /**
     * @return list<array{title: string, source: string, tone: string}>
     */
    public function evidenceForView(): array
    {
        if ($this->evidenceViewItems !== []) {
            return $this->evidenceViewItems;
        }

        return array_map(
            fn (CaseIntelligenceEvidence $item): array => [
                'title' => $item->title,
                'source' => $item->source,
                'tone' => $item->tone,
            ],
            $this->evidence,
        );
    }

    /**
     * Facts-only projection safe to send to a future language enhancer.
     * Prefers Case Story. Excludes raw timeline event collections.
     *
     * @return array<string, mixed>
     */
    public function toLanguageEnhancerPayload(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'incident_id' => $this->incidentId,
            'order_id' => $this->orderId,
            'generated_at' => $this->generatedAt->toIso8601String(),
            'case_story' => $this->caseStory?->toArray(),
            'communication_summary' => $this->communicationSummary?->toArray(),
            'recommended_action' => $this->recommendedAction->toArray(),
            'evidence' => array_map(
                fn (CaseIntelligenceEvidence $item): array => $item->toArray(),
                $this->evidence,
            ),
            'executive_summary' => $this->executiveSummary->toArray(),
            'reasoning' => $this->reasoning === null ? null : [
                'matched_rule_keys' => $this->reasoning->matchedRuleKeys,
                'executive_summary_facts' => $this->reasoning->executiveSummaryFacts,
                'recommended_action_reasoning' => $this->reasoning->recommendedActionReasoning,
                'findings' => array_map(
                    fn (CaseReasoningFinding $finding): array => [
                        'key' => $finding->key,
                        'title' => $finding->title,
                        'category' => $finding->category,
                        'severity' => $finding->severity->value,
                        'explanation' => $finding->explanation,
                    ],
                    $this->reasoning->findings,
                ),
            ],
            'current_status' => [
                'code' => $this->currentStatusCode,
                'label' => $this->currentStatusLabel,
                'sla_status' => $this->slaStatus,
            ],
            'waiting' => [
                'is_waiting' => $this->isWaiting,
                'waiting_party' => $this->waitingParty,
                'waiting_reason_code' => $this->waitingReasonCode,
                'waiting_reason_label' => $this->waitingReasonLabel,
                'waiting_since' => $this->waitingSince?->toIso8601String(),
            ],
            'blockers' => array_map(
                fn (CaseIntelligenceBlocker $blocker): array => $blocker->toArray(),
                $this->blockers,
            ),
            'journey' => $this->journey === null ? null : [
                'phase' => $this->journey->conclusion->type->value,
                'headline' => $this->journey->conclusion->headline,
                'detail' => $this->journey->conclusion->detail,
                'recommendation' => $this->journey->conclusion->recommendation,
                'milestones' => $this->journey->milestoneTitles(),
            ],
            'appointment' => $this->supportAppointment === null ? null : [
                'status' => isset($this->supportAppointment['status'])
                    ? (string) ($this->supportAppointment['status']->value ?? $this->supportAppointment['status'])
                    : null,
                'preferred_date' => isset($this->supportAppointment['preferred_date'])
                    ? (string) $this->supportAppointment['preferred_date']
                    : null,
                'time_slot_label' => $this->supportAppointment['time_slot_label'] ?? null,
                'assignee_name' => $this->supportAppointment['assignee_name'] ?? null,
                'is_active' => (bool) ($this->supportAppointment['is_active'] ?? false),
                'is_completed' => (bool) ($this->supportAppointment['is_completed'] ?? false),
            ],
            'engineer' => [
                'user_id' => $this->engineerUserId,
                'name' => $this->engineerName,
            ],
            'payment' => $this->lastPayment,
            'serial_missing' => $this->serialMissing,
            'customer_summary' => $this->customerSummary,
            'risks' => array_map(
                fn (CaseIntelligenceRisk $risk): array => $risk->toArray(),
                $this->risks,
            ),
            'priority' => [
                'level' => $this->priorityLevel,
                'drivers' => $this->priorityDrivers,
            ],
            'confidence' => [
                'level' => $this->confidenceLevel->value,
                'score' => $this->confidenceScore,
            ],
            'open_questions' => $this->openQuestions,
            'supervisor_insights' => $this->supervisorInsights,
            'customer_mood' => [
                'level' => $this->customerMoodLevel,
            ],
        ];
    }
}
