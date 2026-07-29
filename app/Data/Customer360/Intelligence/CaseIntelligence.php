<?php

namespace App\Data\Customer360\Intelligence;

use App\Enums\AI\AIConfidenceLevel;
use Illuminate\Support\Carbon;

/**
 * Phase-1 Case Intelligence aggregate.
 *
 * Projected from CaseIntelligenceSnapshot — no independent business rules.
 * Presenters consume this shape; they must not recompute intelligence.
 */
readonly class CaseIntelligence
{
    public const SCHEMA_VERSION = '2.0-phase1';

    /**
     * @param  array{whatsapp: int, email: int, phone: int, telegram: int}  $communicationCounts
     * @param  list<CaseIntelligenceRisk>  $risks
     * @param  list<CaseIntelligenceBlocker>  $blockers
     * @param  list<string>  $openQuestions
     * @param  list<CaseIntelligenceEvidence>  $evidence
     */
    public function __construct(
        public int $incidentId,
        public ?int $orderId,
        public Carbon $generatedAt,
        public string $schemaVersion,
        public string $currentStatusCode,
        public string $currentStatusLabel,
        public bool $isWaiting,
        public string $waitingParty,
        public ?string $waitingReasonLabel,
        public ?Carbon $waitingSince,
        public ?string $assignedAgentName,
        public array $communicationCounts,
        public ?CommunicationSummary $communication,
        public ?CaseStory $customerStory,
        public string $sentimentLabel,
        public string $riskLevel,
        public array $risks,
        public array $blockers,
        public array $openQuestions,
        public CaseIntelligenceRecommendedAction $nextBestAction,
        public AIConfidenceLevel $confidenceLevel,
        public int $confidenceScore,
        public string $executiveNarrative,
        public string $opinion,
        public array $evidence,
        public string $sourceSnapshotSchemaVersion,
    ) {}
}
