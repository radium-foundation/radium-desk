<?php

namespace App\Data\Customer360\Intelligence;

use App\Data\AI\AIContextBuildSnapshot;
use App\Data\AI\CustomerJourneyDTO;
use App\Data\TimelineViewModel;
use App\Models\Incident;
use App\Models\Order;
use App\Services\AI\CustomerScopeQueryCache;

/**
 * Deterministic domain facts collected before intelligence projection.
 * Future LLM providers must not replace or invent these values.
 */
readonly class CaseIntelligenceFacts
{
    /**
     * @param  array<string, int>  $customerSummary
     * @param  list<array{label: string, status: string, variant: string}>  $activeServices
     * @param  array<string, mixed>  $enrichmentMetadata
     * @param  array<string, mixed>|null  $waitingStateCard
     * @param  array<string, mixed>|null  $supportAppointment
     */
    public function __construct(
        public Incident $incident,
        public Order $order,
        public array $customerSummary,
        public array $activeServices,
        public array $enrichmentMetadata,
        public TimelineViewModel $timeline,
        public ?array $waitingStateCard,
        public ?array $supportAppointment,
        public CustomerJourneyDTO $customerJourney,
        public CustomerScopeQueryCache $scopeCache,
        public AIContextBuildSnapshot $buildSnapshot,
    ) {}
}
