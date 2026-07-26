<?php

namespace App\Services\Customer360\Intelligence\Builders;

use App\Data\AI\AIContextBuildSnapshot;
use App\Data\AI\CustomerJourneyBuildContext;
use App\Data\Customer360\Intelligence\CaseIntelligenceFacts;
use App\Models\Incident;
use App\Services\AI\CustomerScopeQueryCache;
use App\Services\IncidentWaitingStateService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\Timeline\Customer360TimelineService;
use App\Support\Customer360\Journey\CustomerJourneyBuilder;
use App\Support\Customer360\RdServiceStatusResolver;
use App\Support\Customer360\ScheduledSupportAppointmentContext;

/**
 * Collects authoritative domain facts for case intelligence.
 * No SQL; no recommendation/risk/summary logic.
 */
class CaseIntelligenceFactCollector
{
    public function __construct(
        private readonly Customer360TimelineService $timelineService,
        private readonly IncidentWaitingStateService $waitingStateService,
        private readonly ScheduledSupportAppointmentContext $appointmentContext,
        private readonly CustomerJourneyBuilder $journeyBuilder,
        private readonly RadiumBoxOrderEnrichmentSyncStore $enrichmentSyncStore,
        private readonly RdServiceStatusResolver $rdServiceStatusResolver,
    ) {}

    public function collect(Incident $incident): ?CaseIntelligenceFacts
    {
        $incident->loadMissing(['order.deviceModel', 'activeWaitingState', 'assignee']);
        $order = $incident->order;

        if ($order === null) {
            return null;
        }

        $enrichmentMetadata = $this->enrichmentSyncStore->metadata($order->id) ?? [];
        $activeServices = $this->activeServices($incident, $order, $enrichmentMetadata);
        $scopeCache = new CustomerScopeQueryCache($order->customer_phone);
        $customerSummary = $scopeCache->customerSummary();
        $timeline = $this->timelineService->forOrder($order);
        $waitingStateCard = $this->waitingStateService->customer360Card($incident);
        $supportAppointment = $this->appointmentContext->forIncident($incident);
        $customerJourney = $this->journeyBuilder->forIncident($incident, new CustomerJourneyBuildContext(
            incident: $incident,
            waitingState: $waitingStateCard,
            supportAppointment: $supportAppointment,
            timeline: $timeline,
        ));
        $buildSnapshot = new AIContextBuildSnapshot(
            customerSummary: $customerSummary,
            activeServices: $activeServices,
            enrichmentMetadata: $enrichmentMetadata,
            timeline: $timeline,
            waitingStateCard: $waitingStateCard,
            supportAppointment: $supportAppointment,
            customerJourney: $customerJourney,
        );

        return new CaseIntelligenceFacts(
            incident: $incident,
            order: $order,
            customerSummary: $customerSummary,
            activeServices: $activeServices,
            enrichmentMetadata: $enrichmentMetadata,
            timeline: $timeline,
            waitingStateCard: $waitingStateCard,
            supportAppointment: $supportAppointment,
            customerJourney: $customerJourney,
            scopeCache: $scopeCache,
            buildSnapshot: $buildSnapshot,
        );
    }

    /**
     * @param  array<string, mixed>  $enrichmentMetadata
     * @return list<array{label: string, status: string, variant: string}>
     */
    private function activeServices(Incident $incident, \App\Models\Order $order, array $enrichmentMetadata): array
    {
        if ($order->isInquiryOrder()) {
            return [
                [
                    'label' => 'Enquiry',
                    'status' => 'Open',
                    'variant' => 'info',
                ],
            ];
        }

        $warranty = $this->normalizeServiceStatus($enrichmentMetadata['warranty'] ?? null);
        $amc = $this->normalizeServiceStatus($enrichmentMetadata['amc'] ?? null);
        $rdService = $this->rdServiceStatusResolver->resolve($incident, $order);

        return [
            [
                'label' => 'RD Service',
                'status' => $rdService['status'],
                'variant' => $rdService['variant'],
            ],
            [
                'label' => 'Warranty',
                'status' => $warranty,
                'variant' => $warranty === 'Not Available' ? 'neutral' : 'info',
            ],
            [
                'label' => 'AMC',
                'status' => $amc,
                'variant' => $amc === 'Not Available' ? 'neutral' : 'info',
            ],
        ];
    }

    private function normalizeServiceStatus(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'Not Available';
        }

        return $value;
    }
}
