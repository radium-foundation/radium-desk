<?php

namespace Tests\Unit\Customer360\Intelligence;

use App\Data\AI\CustomerIntelligenceDTO;
use App\Data\AI\DeviceIntelligenceDTO;
use App\Data\AI\OperationalIntelligenceDTO;
use App\Data\Customer360\Intelligence\CaseIntelligenceBlocker;
use App\Data\Customer360\Intelligence\CaseIntelligenceRisk;
use App\Data\Customer360\Intelligence\CaseStory;
use App\Enums\AI\AIRiskLevel;
use App\Enums\SupportAppointmentStatus;
use App\Enums\TimelineEventType;
use App\Services\Customer360\Intelligence\CaseReasoningEngine;
use Illuminate\Support\Carbon;
use Tests\Support\AIContextFactory;
use Tests\Support\CaseIntelligenceSnapshotFactory;
use Tests\TestCase;

class CaseReasoningEngineTest extends TestCase
{
    private CaseReasoningEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ira.case_reasoning.waiting_too_long_days' => 3,
            'ira.case_reasoning.serial_pending_too_long_days' => 2,
            'ira.case_reasoning.long_inactivity_days' => 5,
            'ira.case_reasoning.case_idle_days' => 3,
            'ira.case_reasoning.customer_silent_days' => 2,
            'ira.case_reasoning.repeated_reminders_threshold' => 2,
            'ira.case_reasoning.repeated_reschedules_threshold' => 2,
            'ira.case_reasoning.repeated_cancellations_threshold' => 2,
            'ira.case_reasoning.multiple_assignments_threshold' => 2,
            'ira.case_reasoning.frequent_calls_threshold' => 3,
            'ira.case_reasoning.contact_without_progress_threshold' => 3,
            'ira.case_reasoning.automation_failure_threshold' => 2,
            'ira.case_reasoning.repeated_repairs_threshold' => 2,
        ]);

        $this->engine = new CaseReasoningEngine;
    }

    public function test_builds_structured_case_story_sections(): void
    {
        $snapshot = CaseIntelligenceSnapshotFactory::make([
            'isWaiting' => true,
            'waitingParty' => 'customer',
            'waitingReasonLabel' => 'Serial Number',
            'waitingSince' => now()->subDays(4),
            'serialMissing' => true,
            'blockers' => [
                new CaseIntelligenceBlocker(
                    key: 'serial_missing',
                    label: 'Device serial number is missing',
                    party: 'customer',
                    severity: 'high',
                ),
            ],
        ]);

        $enriched = $this->engine->enrich($snapshot);

        $this->assertInstanceOf(CaseStory::class, $enriched->caseStory);
        $story = $enriched->caseStory->toArray();
        $this->assertArrayHasKey('current_situation', $story);
        $this->assertArrayHasKey('progress', $story);
        $this->assertArrayHasKey('blockers', $story);
        $this->assertArrayHasKey('risks', $story);
        $this->assertArrayHasKey('recommended_action', $story);
        $this->assertArrayHasKey('supporting_facts', $story);
        $this->assertNotEmpty($story['current_situation']);
        $this->assertNotEmpty($story['recommended_action']);
        $this->assertSame(
            $enriched->caseStory->toArray(),
            $enriched->toLanguageEnhancerPayload()['case_story'],
        );
    }

    public function test_detects_waiting_too_long(): void
    {
        $this->assertRuleMatched('waiting_too_long', CaseIntelligenceSnapshotFactory::make([
            'isWaiting' => true,
            'waitingParty' => 'customer',
            'waitingSince' => now()->subDays(4),
        ]));
    }

    public function test_detects_repeated_customer_reminders(): void
    {
        $this->assertRuleMatched('repeated_customer_reminders', CaseIntelligenceSnapshotFactory::make([
            'timeline' => CaseIntelligenceSnapshotFactory::timeline([
                CaseIntelligenceSnapshotFactory::event(TimelineEventType::Notification, 'Support Reminder Sent'),
                CaseIntelligenceSnapshotFactory::event(TimelineEventType::WhatsApp, 'Follow-up reminder sent'),
            ]),
        ]));
    }

    public function test_detects_repeated_customer_reschedules(): void
    {
        $this->assertRuleMatched('repeated_customer_reschedules', CaseIntelligenceSnapshotFactory::make([
            'timeline' => CaseIntelligenceSnapshotFactory::timeline([
                CaseIntelligenceSnapshotFactory::event(TimelineEventType::Appointment, 'Appointment rescheduled'),
                CaseIntelligenceSnapshotFactory::event(TimelineEventType::Appointment, 'Customer reschedule confirmed'),
            ]),
        ]));
    }

    public function test_detects_multiple_engineer_assignments(): void
    {
        $this->assertRuleMatched('multiple_engineer_assignments', CaseIntelligenceSnapshotFactory::make([
            'timeline' => CaseIntelligenceSnapshotFactory::timeline([
                CaseIntelligenceSnapshotFactory::event(TimelineEventType::Assignment, 'Assigned to Ravi'),
                CaseIntelligenceSnapshotFactory::event(TimelineEventType::Assignment, 'Assigned to Amit'),
            ]),
            'engineerName' => 'Amit',
        ]));
    }

    public function test_detects_engineer_no_show(): void
    {
        $this->assertRuleMatched('engineer_no_show', CaseIntelligenceSnapshotFactory::make([
            'supportAppointment' => [
                'status' => SupportAppointmentStatus::Cancelled,
                'preferred_date' => now()->subDays(1),
                'is_active' => false,
                'is_completed' => false,
                'assignee_name' => 'Engineer A',
            ],
            'timeline' => CaseIntelligenceSnapshotFactory::timeline([
                CaseIntelligenceSnapshotFactory::event(TimelineEventType::Appointment, 'Engineer no-show recorded'),
            ]),
        ]));
    }

    public function test_detects_customer_silent(): void
    {
        $this->assertRuleMatched('customer_silent', CaseIntelligenceSnapshotFactory::make([
            'isWaiting' => true,
            'waitingParty' => 'customer',
            'waitingSince' => now()->subDays(3),
            'timeline' => CaseIntelligenceSnapshotFactory::timeline([
                CaseIntelligenceSnapshotFactory::event(
                    TimelineEventType::Notification,
                    'Reminder sent',
                    now()->subDays(2),
                    'System',
                ),
            ]),
        ]));
    }

    public function test_detects_frequent_customer_calls(): void
    {
        $this->assertRuleMatched('frequent_customer_calls', CaseIntelligenceSnapshotFactory::make([
            'timeline' => CaseIntelligenceSnapshotFactory::timeline([
                CaseIntelligenceSnapshotFactory::event(TimelineEventType::IvrCall, 'IVR call 1'),
                CaseIntelligenceSnapshotFactory::event(TimelineEventType::IvrCall, 'IVR call 2'),
                CaseIntelligenceSnapshotFactory::event(TimelineEventType::IvrCall, 'IVR call 3'),
            ]),
        ]));
    }

    public function test_detects_repeated_repairs(): void
    {
        $this->assertRuleMatched('repeated_repairs', CaseIntelligenceSnapshotFactory::make([
            'context' => AIContextFactory::make([
                'customerIntelligence' => new CustomerIntelligenceDTO(
                    lifetimeOrderCount: 2,
                    lifetimeRepairCount: 3,
                    isPremiumCustomer: false,
                    warrantyHistorySummary: 'Active',
                    repeatIssueDetected: true,
                    repeatIssueSummary: 'Repeat screen fault.',
                    averageRepairTurnaroundDays: 4.0,
                    lastInteractionAt: now()->subDay(),
                    lastInteractionSummary: 'Call',
                    outstandingBalance: 0.0,
                    paymentBehaviour: 'Consistent payer',
                ),
            ]),
        ]));
    }

    public function test_detects_payment_overdue(): void
    {
        $this->assertRuleMatched('payment_overdue', CaseIntelligenceSnapshotFactory::make([
            'context' => AIContextFactory::make([
                'customerIntelligence' => new CustomerIntelligenceDTO(
                    lifetimeOrderCount: 1,
                    lifetimeRepairCount: 1,
                    isPremiumCustomer: false,
                    warrantyHistorySummary: 'Active',
                    repeatIssueDetected: false,
                    repeatIssueSummary: null,
                    averageRepairTurnaroundDays: null,
                    lastInteractionAt: now(),
                    lastInteractionSummary: null,
                    outstandingBalance: 1500.0,
                    paymentBehaviour: 'Outstanding balance overdue',
                ),
            ]),
        ]));
    }

    public function test_detects_serial_pending_too_long(): void
    {
        $this->assertRuleMatched('serial_pending_too_long', CaseIntelligenceSnapshotFactory::make([
            'serialMissing' => true,
            'isWaiting' => true,
            'waitingParty' => 'customer',
            'waitingReasonCode' => 'serial',
            'waitingSince' => now()->subDays(3),
        ]));
    }

    public function test_detects_appointment_overdue(): void
    {
        $this->assertRuleMatched('appointment_overdue', CaseIntelligenceSnapshotFactory::make([
            'supportAppointment' => [
                'status' => SupportAppointmentStatus::Scheduled,
                'preferred_date' => Carbon::yesterday(),
                'is_active' => true,
                'is_completed' => false,
                'assignee_name' => 'Engineer A',
            ],
        ]));
    }

    public function test_detects_repeated_cancellations(): void
    {
        $this->assertRuleMatched('repeated_cancellations', CaseIntelligenceSnapshotFactory::make([
            'timeline' => CaseIntelligenceSnapshotFactory::timeline([
                CaseIntelligenceSnapshotFactory::event(TimelineEventType::Appointment, 'Appointment cancelled'),
                CaseIntelligenceSnapshotFactory::event(TimelineEventType::Appointment, 'Second cancellation'),
            ]),
        ]));
    }

    public function test_detects_long_inactivity(): void
    {
        $this->assertRuleMatched('long_inactivity', CaseIntelligenceSnapshotFactory::make([
            'timeline' => CaseIntelligenceSnapshotFactory::timeline([
                CaseIntelligenceSnapshotFactory::event(
                    TimelineEventType::InternalNote,
                    'Old note',
                    now()->subDays(8),
                ),
            ]),
        ]));
    }

    public function test_detects_sla_likely_to_breach(): void
    {
        $this->assertRuleMatched('sla_likely_to_breach', CaseIntelligenceSnapshotFactory::make([
            'slaStatus' => 'warning',
        ]));
    }

    public function test_detects_case_idle(): void
    {
        $this->assertRuleMatched('case_idle', CaseIntelligenceSnapshotFactory::make([
            'isWaiting' => true,
            'waitingParty' => 'customer',
            'waitingSince' => now()->subDays(4),
            'timeline' => CaseIntelligenceSnapshotFactory::timeline([
                CaseIntelligenceSnapshotFactory::event(
                    TimelineEventType::Notification,
                    'Reminder',
                    now()->subDays(4),
                ),
            ]),
        ]));
    }

    public function test_detects_missing_mandatory_information(): void
    {
        $this->assertRuleMatched('missing_mandatory_information', CaseIntelligenceSnapshotFactory::make([
            'serialMissing' => true,
            'openQuestions' => ['What is the device serial number?'],
            'blockers' => [
                new CaseIntelligenceBlocker(
                    key: 'serial_missing',
                    label: 'Device serial number is missing',
                    party: 'customer',
                    severity: 'high',
                ),
            ],
        ]));
    }

    public function test_detects_contacted_many_times_without_progress(): void
    {
        $this->assertRuleMatched('contacted_many_times_without_progress', CaseIntelligenceSnapshotFactory::make([
            'isWaiting' => true,
            'waitingParty' => 'customer',
            'serialMissing' => true,
            'timeline' => CaseIntelligenceSnapshotFactory::timeline([
                CaseIntelligenceSnapshotFactory::event(TimelineEventType::WhatsApp, 'WhatsApp 1'),
                CaseIntelligenceSnapshotFactory::event(TimelineEventType::Email, 'Email 1'),
                CaseIntelligenceSnapshotFactory::event(TimelineEventType::Notification, 'SMS reminder'),
            ]),
        ]));
    }

    public function test_detects_automation_stalled(): void
    {
        $this->assertRuleMatched('automation_stalled', CaseIntelligenceSnapshotFactory::make([
            'context' => AIContextFactory::make([
                'automationStatus' => 'Validation failed',
                'automationHistory' => [
                    [
                        'policy_key' => 'customer_waiting_default',
                        'action_type' => 'reminder',
                        'status' => 'failed',
                        'occurred_at' => now()->subDay(),
                    ],
                    [
                        'policy_key' => 'customer_waiting_default',
                        'action_type' => 'reminder',
                        'status' => 'failed',
                        'occurred_at' => now(),
                    ],
                ],
                'operationalIntelligence' => new OperationalIntelligenceDTO(
                    waitingState: null,
                    slaState: 'Within SLA',
                    priority: 'Normal',
                    assignment: null,
                    queuePosition: null,
                    automationHistory: [],
                    automationStatus: 'Validation failed',
                    timelineSummary: '',
                    internalRemarksSummary: '',
                ),
            ]),
        ]));
    }

    public function test_automation_stalled_ignores_validation_failed_label_without_failed_executions(): void
    {
        $enriched = $this->engine->enrich(CaseIntelligenceSnapshotFactory::make([
            'context' => AIContextFactory::make([
                'automationStatus' => 'Validation failed',
                'automationHistory' => [],
                'operationalIntelligence' => new OperationalIntelligenceDTO(
                    waitingState: null,
                    slaState: 'Within SLA',
                    priority: 'Normal',
                    assignment: null,
                    queuePosition: null,
                    automationHistory: [],
                    automationStatus: 'Validation failed',
                    timelineSummary: '',
                    internalRemarksSummary: '',
                ),
            ]),
        ]));

        $this->assertNotContains('automation_stalled', $enriched->reasoning?->matchedRuleKeys ?? []);
    }

    public function test_missing_mandatory_ignores_open_questions(): void
    {
        $enriched = $this->engine->enrich(CaseIntelligenceSnapshotFactory::make([
            'serialMissing' => false,
            'openQuestions' => [
                'Has the customer been reminded about: Waiting for Payment?',
                'What is the device serial number?',
            ],
            'context' => AIContextFactory::make([
                'serialMissing' => false,
                'customerPhone' => '9123456789',
            ]),
        ]));

        $this->assertNotContains('missing_mandatory_information', $enriched->reasoning?->matchedRuleKeys ?? []);
    }

    public function test_high_priority_unattended_uses_incident_updated_at_fallback(): void
    {
        $this->assertRuleMatched('high_priority_unattended', CaseIntelligenceSnapshotFactory::make([
            'priorityLevel' => 'high',
            'timeline' => null,
            'incidentUpdatedAt' => now()->subDays(2),
            'incidentCreatedAt' => now()->subDays(4),
            'context' => AIContextFactory::make([
                'highPriority' => true,
                'recentActivities' => [],
            ]),
        ]));
    }

    public function test_detects_premium_customer_at_risk(): void
    {
        $this->assertRuleMatched('premium_customer_at_risk', CaseIntelligenceSnapshotFactory::make([
            'isWaiting' => true,
            'waitingParty' => 'customer',
            'waitingSince' => now()->subDays(4),
            'slaStatus' => 'warning',
            'context' => AIContextFactory::make([
                'customerIntelligence' => new CustomerIntelligenceDTO(
                    lifetimeOrderCount: 5,
                    lifetimeRepairCount: 2,
                    isPremiumCustomer: true,
                    warrantyHistorySummary: 'Active',
                    repeatIssueDetected: false,
                    repeatIssueSummary: null,
                    averageRepairTurnaroundDays: null,
                    lastInteractionAt: now()->subDays(4),
                    lastInteractionSummary: null,
                    outstandingBalance: 0.0,
                    paymentBehaviour: 'Consistent payer',
                ),
            ]),
        ]));
    }

    public function test_detects_high_priority_unattended(): void
    {
        $this->assertRuleMatched('high_priority_unattended', CaseIntelligenceSnapshotFactory::make([
            'priorityLevel' => 'critical',
            'context' => AIContextFactory::make([
                'highPriority' => true,
            ]),
            'timeline' => CaseIntelligenceSnapshotFactory::timeline([
                CaseIntelligenceSnapshotFactory::event(
                    TimelineEventType::InternalNote,
                    'Old note',
                    now()->subDays(2),
                ),
            ]),
        ]));
    }

    public function test_detects_waiting_on_internal_too_long(): void
    {
        $this->assertRuleMatched('waiting_on_internal_too_long', CaseIntelligenceSnapshotFactory::make([
            'isWaiting' => true,
            'waitingParty' => 'engineer',
            'waitingSince' => now()->subDays(2),
            'engineerName' => 'Ravi',
        ]));
    }

    public function test_enriches_risk_and_blocker_explanations_and_recommended_action_reasoning(): void
    {
        $snapshot = CaseIntelligenceSnapshotFactory::make([
            'serialMissing' => true,
            'isWaiting' => true,
            'waitingParty' => 'customer',
            'waitingSince' => now()->subDays(3),
            'blockers' => [
                new CaseIntelligenceBlocker(
                    key: 'serial_missing',
                    label: 'Device serial number is missing',
                    party: 'customer',
                    severity: 'high',
                ),
            ],
            'risks' => [
                new CaseIntelligenceRisk(
                    key: 'data_quality_risk',
                    label: 'Data Quality Risk',
                    category: 'data_quality',
                    severity: AIRiskLevel::Medium,
                ),
            ],
            'context' => AIContextFactory::make([
                'serialMissing' => true,
                'deviceIntelligence' => new DeviceIntelligenceDTO(
                    model: 'MFS 110',
                    category: 'General',
                    variant: 'MFS 110 E3',
                    serialAvailable: false,
                    previousRepairsOnSerial: 0,
                    previousRepairsOnModel: 0,
                    commonFailurePatterns: [],
                    partsFrequentlyReplaced: [],
                ),
            ]),
        ]);

        $enriched = $this->engine->enrich($snapshot);

        $this->assertNotNull($enriched->reasoning);
        $this->assertNotEmpty($enriched->reasoning->blockerExplanations['serial_missing']);
        $this->assertNotEmpty($enriched->reasoning->riskExplanations['data_quality_risk']);
        $this->assertNotEmpty($enriched->reasoning->recommendedActionReasoning);
        $this->assertNotEmpty($enriched->reasoning->executiveSummaryFacts);
        $this->assertNotNull($enriched->blockers[0]->explanation);
        $this->assertTrue(count($enriched->executiveSummary->executiveSummary) >= 1);
        $this->assertTrue(count($enriched->recommendedAction->rationale) >= 1);
    }

    private function assertRuleMatched(string $ruleKey, $snapshot): void
    {
        $enriched = $this->engine->enrich($snapshot);

        $this->assertNotNull($enriched->reasoning, "Expected reasoning for {$ruleKey}");
        $this->assertContains(
            $ruleKey,
            $enriched->reasoning->matchedRuleKeys,
            'Matched keys: '.implode(', ', $enriched->reasoning->matchedRuleKeys),
        );

        $findingKeys = array_map(fn ($f) => $f->key, $enriched->reasoning->findings);
        $this->assertContains($ruleKey, $findingKeys);
    }
}
