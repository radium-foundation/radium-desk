<?php

namespace Tests\Unit\Customer360\Intelligence;

use App\Data\AI\IRAExecutiveSummaryDTO;
use App\Data\Customer360\Intelligence\CaseIntelligenceBlocker;
use App\Data\Customer360\Intelligence\CaseIntelligenceEvidence;
use App\Data\Customer360\Intelligence\CaseIntelligenceRecommendedAction;
use App\Data\Customer360\Intelligence\CaseIntelligenceRisk;
use App\Data\Customer360\Intelligence\CaseIntelligenceSnapshot;
use App\Enums\AI\AIConfidenceLevel;
use App\Enums\AI\AIRiskLevel;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\AI\AIService;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaseIntelligenceSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_snapshot_is_immutable_readonly_and_serializes_enhancer_payload(): void
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-SNAP-1',
            'serial_number' => null,
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'customer_name' => 'Snapshot Customer',
            'customer_phone' => '9123456711',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Snapshot case',
            'description' => 'Test',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $bundle = app(AIService::class)->buildBundle($incident->fresh(['order', 'assignee']));

        $snapshot = new CaseIntelligenceSnapshot(
            incidentId: $incident->id,
            orderId: $order->id,
            generatedAt: now(),
            schemaVersion: CaseIntelligenceSnapshot::SCHEMA_VERSION,
            currentStatusCode: 'blocked_serial',
            currentStatusLabel: 'Blocked — serial required',
            slaStatus: 'within_sla',
            isWaiting: false,
            waitingParty: 'none',
            waitingReasonCode: null,
            waitingReasonLabel: null,
            waitingSince: null,
            blockers: [
                new CaseIntelligenceBlocker(
                    key: 'serial_missing',
                    label: 'Device serial number is missing',
                    party: 'customer',
                    severity: 'high',
                ),
            ],
            journey: null,
            supportAppointment: null,
            engineerUserId: $agent->id,
            engineerName: $agent->name,
            lastPayment: null,
            serialMissing: true,
            customerSummary: ['open_cases' => 1],
            activeServices: [],
            enrichmentMetadata: [],
            waitingStateCard: null,
            timeline: null,
            risks: [
                new CaseIntelligenceRisk(
                    key: 'data_quality_risk',
                    label: 'Data Quality Risk',
                    category: 'data_quality',
                    severity: AIRiskLevel::Medium,
                ),
            ],
            priorityLevel: 'normal',
            priorityDrivers: ['Active blockers'],
            recommendedAction: new CaseIntelligenceRecommendedAction(
                actionKey: 'request_serial',
                label: 'Request Serial',
                rationale: ['Serial missing'],
                confidence: 'high',
                recommendationText: 'Request the serial number from the customer immediately.',
            ),
            executiveSummary: new IRAExecutiveSummaryDTO(
                executiveSummary: ['Customer purchased an FM220 and currently has one active repair.'],
                opinion: 'This case is blocked until the device serial number is received from the customer.',
                recommendation: 'Request the serial number from the customer immediately.',
            ),
            evidence: [
                new CaseIntelligenceEvidence(
                    id: 'serial',
                    title: 'Serial missing',
                    source: 'IRA',
                    tone: 'negative',
                ),
            ],
            confidenceLevel: AIConfidenceLevel::Medium,
            confidenceScore: 70,
            openQuestions: ['What is the device serial number?'],
            supervisorInsights: [],
            customerMoodLevel: 'unknown',
            aiBundle: $bundle,
            context: $bundle->context,
            operationsAdvisorInsights: [],
        );

        $this->assertTrue((new \ReflectionClass($snapshot))->isReadOnly());
        $this->assertSame('1.0', $snapshot->schemaVersion);
        $this->assertSame('request_serial', $snapshot->recommendedAction->actionKey);
        $this->assertCount(1, $snapshot->blockers);
        $this->assertSame('serial_missing', $snapshot->blockers[0]->key);

        $payload = $snapshot->toLanguageEnhancerPayload();
        $this->assertSame($incident->id, $payload['incident_id']);
        $this->assertSame('blocked_serial', $payload['current_status']['code']);
        $this->assertTrue($payload['serial_missing']);
        $this->assertSame('unknown', $payload['customer_mood']['level']);
        $this->assertSame(
            'Request the serial number from the customer immediately.',
            $payload['executive_summary']['recommendation'],
        );
    }
}
