<?php

namespace Tests\Support;

use App\Data\AI\AIIncidentBundle;
use App\Data\AI\AIResponseDTO;
use App\Data\AI\AIWorkbenchDTO;
use App\Data\AI\IRAExecutiveSummaryDTO;
use App\Data\Customer360\Intelligence\CaseIntelligenceRecommendedAction;
use App\Data\Customer360\Intelligence\CaseIntelligenceSnapshot;
use App\Data\TimelineDayGroup;
use App\Data\TimelineEvent;
use App\Data\TimelineViewModel;
use App\Enums\AI\AIConfidenceLevel;
use App\Enums\TimelineDayBucket;
use App\Services\AI\CustomerScopeQueryCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CaseIntelligenceSnapshotFactory
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function make(array $overrides = []): CaseIntelligenceSnapshot
    {
        $context = $overrides['context'] ?? AIContextFactory::make($overrides['contextOverrides'] ?? []);

        $response = new AIResponseDTO(
            customerSummary: 'Customer summary',
            incidentSummary: 'Incident summary',
            warrantyStatus: $context->warrantyStatus,
            paymentStatus: null,
            riskIndicators: $context->riskIndicators,
            suggestedNextActions: [],
            suggestedCustomerReply: '',
            confidence: 0.7,
            confidenceLevel: AIConfidenceLevel::Medium,
            confidenceScore: 70,
            classification: 'General',
            estimatedResolution: 'Unknown',
            recommendationExplanation: null,
            providerName: 'null',
            customerIntelligence: $context->customerIntelligence,
            deviceIntelligence: $context->deviceIntelligence,
            operationalIntelligence: $context->operationalIntelligence,
            businessIntelligence: $context->businessIntelligence,
            knowledge: $context->knowledge,
        );

        $bundle = new AIIncidentBundle(
            response: $response,
            context: $context,
            knowledge: $context->knowledge,
            scopeCache: new CustomerScopeQueryCache($context->customerPhone),
        );

        $workbench = new AIWorkbenchDTO(
            incidentId: $context->incidentId,
            scenario: 'general',
            scenarioLabel: 'General',
            customerReplies: [],
            internalNote: [
                'content' => '',
                'confidence' => 'medium',
                'confidence_score' => 70,
                'explanation' => '',
            ],
            checklist: [],
            workflowSuggestions: [],
            confidenceLevel: AIConfidenceLevel::Medium,
            confidenceScore: 70,
            confidenceExplanation: '',
            providerName: 'null',
            generatedAt: now(),
        );

        $defaults = [
            'incidentId' => $context->incidentId,
            'orderId' => 1,
            'generatedAt' => now(),
            'schemaVersion' => CaseIntelligenceSnapshot::SCHEMA_VERSION,
            'currentStatusCode' => 'open',
            'currentStatusLabel' => 'Open',
            'slaStatus' => 'within_sla',
            'isWaiting' => false,
            'waitingParty' => 'none',
            'waitingReasonCode' => null,
            'waitingReasonLabel' => null,
            'waitingSince' => null,
            'blockers' => [],
            'journey' => null,
            'supportAppointment' => null,
            'engineerUserId' => null,
            'engineerName' => null,
            'lastPayment' => null,
            'serialMissing' => false,
            'customerSummary' => $context->customerSummary,
            'activeServices' => [],
            'enrichmentMetadata' => [],
            'waitingStateCard' => null,
            'timeline' => null,
            'risks' => [],
            'priorityLevel' => 'normal',
            'priorityDrivers' => [],
            'recommendedAction' => new CaseIntelligenceRecommendedAction(
                actionKey: 'follow_up',
                label: 'Follow up',
                rationale: ['Default follow-up'],
                confidence: 'medium',
                recommendationText: 'Follow up with the customer.',
            ),
            'executiveSummary' => new IRAExecutiveSummaryDTO(
                executiveSummary: ['Case is open.'],
                opinion: 'Needs attention.',
                recommendation: 'Follow up with the customer.',
            ),
            'evidence' => [],
            'confidenceLevel' => AIConfidenceLevel::Medium,
            'confidenceScore' => 70,
            'openQuestions' => [],
            'supervisorInsights' => [],
            'customerMoodLevel' => 'unknown',
            'aiBundle' => $bundle,
            'context' => $context,
            'operationsAdvisorInsights' => [],
            'workbench' => $workbench,
            'advisorViewModel' => null,
            'evidenceViewItems' => [],
            'reasoning' => null,
            'caseStory' => null,
            'incidentCreatedAt' => null,
            'incidentUpdatedAt' => null,
        ];

        $data = array_merge($defaults, $overrides);
        unset($data['contextOverrides']);

        return new CaseIntelligenceSnapshot(
            incidentId: $data['incidentId'],
            orderId: $data['orderId'],
            generatedAt: $data['generatedAt'],
            schemaVersion: $data['schemaVersion'],
            currentStatusCode: $data['currentStatusCode'],
            currentStatusLabel: $data['currentStatusLabel'],
            slaStatus: $data['slaStatus'],
            isWaiting: $data['isWaiting'],
            waitingParty: $data['waitingParty'],
            waitingReasonCode: $data['waitingReasonCode'],
            waitingReasonLabel: $data['waitingReasonLabel'],
            waitingSince: $data['waitingSince'],
            blockers: $data['blockers'],
            journey: $data['journey'],
            supportAppointment: $data['supportAppointment'],
            engineerUserId: $data['engineerUserId'],
            engineerName: $data['engineerName'],
            lastPayment: $data['lastPayment'],
            serialMissing: $data['serialMissing'],
            customerSummary: $data['customerSummary'],
            activeServices: $data['activeServices'],
            enrichmentMetadata: $data['enrichmentMetadata'],
            waitingStateCard: $data['waitingStateCard'],
            timeline: $data['timeline'],
            risks: $data['risks'],
            priorityLevel: $data['priorityLevel'],
            priorityDrivers: $data['priorityDrivers'],
            recommendedAction: $data['recommendedAction'],
            executiveSummary: $data['executiveSummary'],
            evidence: $data['evidence'],
            confidenceLevel: $data['confidenceLevel'],
            confidenceScore: $data['confidenceScore'],
            openQuestions: $data['openQuestions'],
            supervisorInsights: $data['supervisorInsights'],
            customerMoodLevel: $data['customerMoodLevel'],
            aiBundle: $data['aiBundle'],
            context: $data['context'],
            operationsAdvisorInsights: $data['operationsAdvisorInsights'],
            workbench: $data['workbench'],
            advisorViewModel: $data['advisorViewModel'],
            evidenceViewItems: $data['evidenceViewItems'],
            reasoning: $data['reasoning'],
            caseStory: $data['caseStory'],
            incidentCreatedAt: $data['incidentCreatedAt'],
            incidentUpdatedAt: $data['incidentUpdatedAt'],
        );
    }

    /**
     * @param  list<TimelineEvent>  $events
     */
    public static function timeline(array $events): TimelineViewModel
    {
        return new TimelineViewModel(
            groups: collect([
                new TimelineDayGroup(
                    bucket: TimelineDayBucket::Today,
                    events: Collection::make($events),
                ),
            ]),
            totalCount: count($events),
            loadedCount: count($events),
            offset: 0,
            limit: 100,
            hasMore: false,
        );
    }

    public static function event(
        \App\Enums\TimelineEventType $type,
        string $title,
        ?Carbon $occurredAt = null,
        string $actorName = 'System',
    ): TimelineEvent {
        return new TimelineEvent(
            type: $type,
            occurredAt: $occurredAt ?? now(),
            title: $title,
            actor: new \App\Data\TimelineActor($actorName),
            dedupeKey: uniqid('evt_', true),
        );
    }
}
