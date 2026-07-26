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
 */
readonly class CaseIntelligenceSnapshot
{
    public const SCHEMA_VERSION = '1.1';

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
    ) {}

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
     * Excludes raw timeline event collections.
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
            'recommended_action' => $this->recommendedAction->toArray(),
            'executive_summary' => $this->executiveSummary->toArray(),
            'evidence' => array_map(
                fn (CaseIntelligenceEvidence $item): array => $item->toArray(),
                $this->evidence,
            ),
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
