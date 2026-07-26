<?php

namespace Tests\Unit\Customer360\Intelligence;

use App\Data\AI\OperationalIntelligenceDTO;
use App\Data\Customer360\Intelligence\CaseIntelligenceBlocker;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\Customer360\Intelligence\CaseIntelligenceEngine;
use App\Services\Customer360\Intelligence\CaseReasoningEngine;
use App\Services\IncidentReferenceService;
use App\Support\Customer360\Customer360IraAdvisorPresenter;
use App\Support\Customer360\Customer360IraPanelPresenter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AIContextFactory;
use Tests\Support\CaseIntelligenceSnapshotFactory;
use Tests\TestCase;

/**
 * Regression coverage for validation fixes Q1, Q2, Q4, Q6, Q7.
 */
class CaseIntelligenceValidationFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['ira.case_intelligence_engine.enabled' => true]);
    }

    public function test_q1_validation_failed_label_does_not_trigger_automation_stalled(): void
    {
        $reasoner = new CaseReasoningEngine;
        $enriched = $reasoner->enrich(CaseIntelligenceSnapshotFactory::make([
            'context' => AIContextFactory::make([
                'automationStatus' => 'Validation failed',
                'automationHistory' => [
                    [
                        'policy_key' => 'x',
                        'action_type' => 'y',
                        'status' => 'success',
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

        $this->assertNotContains('automation_stalled', $enriched->reasoning?->matchedRuleKeys ?? []);
    }

    public function test_q1_exact_failed_executions_still_trigger_automation_stalled(): void
    {
        $reasoner = new CaseReasoningEngine;
        $enriched = $reasoner->enrich(CaseIntelligenceSnapshotFactory::make([
            'context' => AIContextFactory::make([
                'automationStatus' => 'Assigned to team member',
                'automationHistory' => [
                    [
                        'policy_key' => 'customer_waiting_default',
                        'action_type' => 'reminder',
                        'status' => 'failed',
                        'occurred_at' => now()->subDay(),
                    ],
                    [
                        'policy_key' => 'customer_waiting_default',
                        'action_type' => 'followup',
                        'status' => 'failed',
                        'occurred_at' => now(),
                    ],
                ],
            ]),
        ]));

        $this->assertContains('automation_stalled', $enriched->reasoning?->matchedRuleKeys ?? []);
    }

    public function test_q2_canonical_recommendation_matches_across_surfaces(): void
    {
        [$incident, , $agent] = $this->createSerialPendingIncident();
        $this->actingAs($agent);

        $snapshot = app(CaseIntelligenceEngine::class)->build($incident);
        $this->assertNotNull($snapshot);

        $canonical = (string) ($snapshot->recommendedAction->recommendationText
            ?? $snapshot->recommendedAction->label);

        $this->assertNotSame('', $canonical);
        $this->assertSame($canonical, $snapshot->executiveSummary->recommendation);
        $this->assertSame($canonical, $snapshot->advisorViewModel['recommendation_text'] ?? null);
        $this->assertContains($canonical, $snapshot->caseStory?->recommendedAction ?? []);

        $panel = app(Customer360IraPanelPresenter::class)->present($snapshot, $incident);
        $this->assertSame($canonical, $panel['recommended_action']['text']);
        $this->assertSame($canonical, $panel['summary_payload']['recommendation']);

        $advisor = app(Customer360IraAdvisorPresenter::class)->presentFromSnapshot($snapshot);
        $this->assertSame($snapshot->recommendedAction->actionKey, $advisor['recommended_action']['key'] ?? null);
        $this->assertSame($canonical, $advisor['recommendation_text'] ?? null);
    }

    public function test_q4_open_questions_are_not_mandatory_information(): void
    {
        $reasoner = new CaseReasoningEngine;
        $enriched = $reasoner->enrich(CaseIntelligenceSnapshotFactory::make([
            'serialMissing' => false,
            'blockers' => [],
            'openQuestions' => [
                'Has the customer been reminded about: Waiting for Payment?',
                'Can warranty coverage be verified?',
            ],
            'context' => AIContextFactory::make([
                'serialMissing' => false,
                'customerPhone' => '9000000001',
            ]),
        ]));

        $this->assertNotContains('missing_mandatory_information', $enriched->reasoning?->matchedRuleKeys ?? []);
    }

    public function test_q4_structured_serial_and_phone_gaps_still_trigger(): void
    {
        $reasoner = new CaseReasoningEngine;
        $enriched = $reasoner->enrich(CaseIntelligenceSnapshotFactory::make([
            'serialMissing' => true,
            'blockers' => [
                new CaseIntelligenceBlocker(
                    key: 'serial_missing',
                    label: 'Device serial number is missing',
                    party: 'customer',
                    severity: 'high',
                ),
            ],
            'openQuestions' => ['Has the customer been reminded about: Waiting for Serial Number?'],
            'context' => AIContextFactory::make([
                'serialMissing' => true,
                'customerPhone' => '',
            ]),
        ]));

        $this->assertContains('missing_mandatory_information', $enriched->reasoning?->matchedRuleKeys ?? []);
        $finding = collect($enriched->reasoning?->findings ?? [])
            ->first(fn ($f) => $f->key === 'missing_mandatory_information');
        $this->assertNotNull($finding);
        $this->assertSame(
            ['device serial number', 'customer phone'],
            $finding->signals['missing_items'] ?? [],
        );
        $this->assertStringNotContainsString('Has the customer been reminded', $finding->explanation);
    }

    public function test_q6_overdue_appointment_becomes_blocker_status_evidence_and_recommendation(): void
    {
        [$incident, , $agent] = $this->createOpenIncident();
        $this->actingAs($agent);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->subDays(2)->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => $incident->order->customer_phone,
            'normalized_phone' => $incident->order->customer_phone,
            'status' => SupportAppointmentStatus::Scheduled,
        ]);

        $snapshot = app(CaseIntelligenceEngine::class)->build($incident->fresh(['order', 'assignee', 'activeWaitingState']), true);
        $this->assertNotNull($snapshot);

        $this->assertSame('appointment_overdue', $snapshot->currentStatusCode);
        $this->assertSame('Support appointment overdue', $snapshot->currentStatusLabel);
        $this->assertTrue(collect($snapshot->blockers)->contains(fn ($b) => $b->key === 'appointment_overdue'));
        $this->assertContains('appointment_overdue', $snapshot->reasoning?->matchedRuleKeys ?? []);

        $appointmentEvidence = collect($snapshot->evidence)->first(fn ($e) => $e->id === 'appointment');
        $this->assertNotNull($appointmentEvidence);
        $this->assertSame('Support appointment overdue', $appointmentEvidence->title);
        $this->assertSame('negative', $appointmentEvidence->tone);

        $this->assertSame('appointment_overdue', $snapshot->recommendedAction->matchedRuleId);
        $this->assertSame('contact_customer', $snapshot->recommendedAction->actionKey);
        $this->assertSame(
            $snapshot->recommendedAction->recommendationText,
            $snapshot->executiveSummary->recommendation,
        );
    }

    public function test_q7_engine_snapshot_exposes_incident_timestamps_for_idle_fallback(): void
    {
        [$incident, , $agent] = $this->createOpenIncident(['high_priority' => true]);
        $this->actingAs($agent);

        Incident::query()->whereKey($incident->id)->update([
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(2),
        ]);

        $snapshot = app(CaseIntelligenceEngine::class)->build($incident->fresh(['order', 'assignee']), true);
        $this->assertNotNull($snapshot);
        $this->assertNotNull($snapshot->incidentCreatedAt);
        $this->assertNotNull($snapshot->incidentUpdatedAt);
        $this->assertTrue($snapshot->incidentUpdatedAt->lte(now()->subDay()));

        // When activity history is empty, reasoner must use those structured timestamps (Q7).
        $probe = CaseIntelligenceSnapshotFactory::make([
            'priorityLevel' => 'high',
            'timeline' => null,
            'incidentCreatedAt' => $snapshot->incidentCreatedAt,
            'incidentUpdatedAt' => $snapshot->incidentUpdatedAt,
            'context' => AIContextFactory::make([
                'highPriority' => true,
                'recentActivities' => [],
            ]),
        ]);

        $enriched = (new CaseReasoningEngine)->enrich($probe);
        $this->assertContains('high_priority_unattended', $enriched->reasoning?->matchedRuleKeys ?? []);
    }

    /**
     * @return array{0: Incident, 1: Order, 2: User}
     */
    private function createSerialPendingIncident(): array
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-VAL-FIX-SERIAL',
            'serial_number' => null,
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'customer_name' => 'Val Fix Customer',
            'customer_phone' => '9123456711',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Serial pending',
            'description' => 'Missing serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
            'created_at' => now()->subDays(2),
        ]);

        return [$incident->fresh(['order', 'assignee']), $order, $agent];
    }

    /**
     * @param  array<string, mixed>  $incidentOverrides
     * @return array{0: Incident, 1: Order, 2: User}
     */
    private function createOpenIncident(array $incidentOverrides = []): array
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-VAL-FIX-'.uniqid(),
            'serial_number' => 'SN-VAL-FIX-1',
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'customer_name' => 'Val Fix Customer',
            'customer_phone' => '9123456712',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create(array_merge([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Open validation case',
            'description' => 'Open case',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
            'high_priority' => false,
        ], $incidentOverrides));

        return [$incident->fresh(['order', 'assignee']), $order, $agent];
    }
}
