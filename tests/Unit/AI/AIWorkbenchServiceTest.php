<?php

namespace Tests\Unit\AI;

use App\Data\AI\AIIncidentBundle;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WaitingReason;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\User;
use App\Services\AI\AIService;
use App\Services\AI\AIWorkbenchService;
use App\Services\Customer360\Intelligence\CaseIntelligenceEngine;
use App\Services\Customer360Service;
use App\Services\IncidentReferenceService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\AIContextFactory;
use Tests\TestCase;

class AIWorkbenchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_generates_whatsapp_email_and_internal_replies(): void
    {
        [$incident, $bundle] = $this->createIncidentBundle(serialMissing: true);

        $workbench = app(AIWorkbenchService::class)->buildFromBundle($incident, $bundle);

        $this->assertSame('waiting_for_serial', $workbench->scenario);
        $this->assertCount(3, $workbench->customerReplies);
        $this->assertSame('WhatsApp', $workbench->customerReplies[0]['channel_label']);
        $this->assertStringContainsString('serial number', $workbench->customerReplies[0]['content']);
        $this->assertStringContainsString('Subject:', $workbench->customerReplies[1]['content']);
        $this->assertStringContainsString('Serial pending', $workbench->customerReplies[2]['content']);
    }

    public function test_generates_internal_note_with_repeat_history_guidance(): void
    {
        [$incident, $bundle] = $this->createIncidentBundle(repeatTitle: true);

        $workbench = app(AIWorkbenchService::class)->buildFromBundle($incident, $bundle);

        $this->assertStringContainsString('Repeat repair history', $workbench->internalNote['content']);
        $this->assertStringContainsString('prior notes', $workbench->internalNote['content']);
        $this->assertNotSame('', $workbench->internalNote['explanation']);
    }

    public function test_generates_checklist_items(): void
    {
        [$incident, $bundle] = $this->createIncidentBundle(serialMissing: true);

        $workbench = app(AIWorkbenchService::class)->buildFromBundle($incident, $bundle);
        $labels = array_column($workbench->checklist, 'label');

        $this->assertContains('Verify serial number', $labels);
        $this->assertContains('Contact customer', $labels);
        $this->assertContains('Close after final follow-up if unreachable', $labels);
        $this->assertNotContains('Verify warranty', $labels);
        $this->assertNotContains('Run diagnostics', $labels);
        $this->assertNotContains('Confirm accessories received', $labels);
        $this->assertNotContains('Update customer', $labels);
    }

    public function test_checklist_marks_payment_received_when_present(): void
    {
        [$incident, $bundle] = $this->createIncidentBundle(serialMissing: true);

        $context = AIContextFactory::make([
            'incidentId' => $bundle->context->incidentId,
            'incidentReference' => $bundle->context->incidentReference,
            'serialMissing' => true,
            'customerName' => $bundle->context->customerName,
            'lastPayment' => ['label' => 'Payment received', 'occurred_at' => now()],
            'customerIntelligence' => $bundle->context->customerIntelligence,
            'deviceIntelligence' => $bundle->context->deviceIntelligence,
            'operationalIntelligence' => $bundle->context->operationalIntelligence,
            'businessIntelligence' => $bundle->context->businessIntelligence,
            'knowledge' => $bundle->knowledge,
        ]);

        $workbench = app(AIWorkbenchService::class)->buildFromBundle(
            $incident,
            new AIIncidentBundle(
                response: $bundle->response,
                context: $context,
                knowledge: $bundle->knowledge,
                scopeCache: $bundle->scopeCache,
            ),
        );

        $payment = collect($workbench->checklist)->firstWhere('key', 'payment_received');

        $this->assertNotNull($payment);
        $this->assertTrue($payment['done']);
        $this->assertSame('Payment received', $payment['label']);
    }

    public function test_generates_workflow_suggestions_without_execution(): void
    {
        [$incident, $bundle] = $this->createIncidentBundle(serialMissing: true, warrantyExpired: true);

        $workbench = app(AIWorkbenchService::class)->buildFromBundle($incident, $bundle);
        $labels = array_column($workbench->workflowSuggestions, 'label');

        $this->assertContains('Assign Engineer', $labels);
        $this->assertContains('Request Serial', $labels);
        $this->assertContains('Send Estimate', $labels);
    }

    public function test_includes_confidence_explanation_from_ai_response(): void
    {
        [$incident, $bundle] = $this->createIncidentBundle(serialMissing: true);

        $workbench = app(AIWorkbenchService::class)->buildFromBundle($incident, $bundle);

        $this->assertSame($bundle->response->confidenceLevel, $workbench->confidenceLevel);
        $this->assertSame($bundle->response->confidenceScore, $workbench->confidenceScore);
        $this->assertNotNull($workbench->confidenceExplanation);
    }

    public function test_active_waiting_classifies_waiting_for_customer(): void
    {
        [$incident, $bundle] = $this->createIncidentBundle(
            orderId: 'RD-WB-ACTIVE',
            waitingReason: WaitingReason::CustomerApproval,
        );

        $workbench = app(AIWorkbenchService::class)->buildFromBundle(
            $incident,
            $this->withWaitingState($bundle, [
                'reason_label' => WaitingReason::CustomerApproval->label(),
                'started_at' => now()->subDay(),
                'customer_waiting_since' => now()->subDay(),
            ], serialMissing: false),
        );

        $this->assertSame('waiting_for_customer', $workbench->scenario);
        $this->assertSame(
            'Waiting for '.WaitingReason::CustomerApproval->label(),
            $workbench->scenarioLabel,
        );
    }

    public function test_lifecycle_only_waiting_does_not_classify_as_waiting_for_customer(): void
    {
        [$incident, $bundle] = $this->createIncidentBundle(
            orderId: 'RD-WB-LIFE',
            status: IncidentStatus::Open,
        );

        $workbench = app(AIWorkbenchService::class)->buildFromBundle(
            $incident,
            $this->withWaitingState($bundle, $this->lifecycleOnlyWaitingState(), serialMissing: false),
        );

        $this->assertSame('general_update', $workbench->scenario);
        $this->assertSame('Status update', $workbench->scenarioLabel);
        $this->assertStringNotContainsString('Waiting for', $workbench->scenarioLabel);
    }

    public function test_empty_waiting_object_is_not_active_wait(): void
    {
        [$incident, $bundle] = $this->createIncidentBundle(orderId: 'RD-WB-EMPTY');

        $workbench = app(AIWorkbenchService::class)->buildFromBundle(
            $incident,
            $this->withWaitingState($bundle, [], serialMissing: false),
        );

        $this->assertSame('general_update', $workbench->scenario);
    }

    public function test_null_waiting_is_not_active_wait(): void
    {
        [$incident, $bundle] = $this->createIncidentBundle(orderId: 'RD-WB-NULL');

        $workbench = app(AIWorkbenchService::class)->buildFromBundle(
            $incident,
            $this->withWaitingState($bundle, null, serialMissing: false),
        );

        $this->assertSame('general_update', $workbench->scenario);
    }

    public function test_rd3443709_lifecycle_only_awaiting_product_details_does_not_throw(): void
    {
        [$incident, $bundle] = $this->createIncidentBundle(
            orderId: 'RD3443709',
            status: IncidentStatus::AwaitingProductDetails,
            serialMissing: false,
        );

        $workbench = app(AIWorkbenchService::class)->buildFromBundle(
            $incident,
            $this->withWaitingState(
                $bundle,
                $this->lifecycleOnlyWaitingState(waitingReasonLabel: 'Serial Number'),
                serialMissing: false,
            ),
        );

        $this->assertSame('general_update', $workbench->scenario);
        $this->assertSame('Status update', $workbench->scenarioLabel);
    }

    public function test_in_progress_with_lifecycle_only_uses_device_received_not_waiting(): void
    {
        [$incident, $bundle] = $this->createIncidentBundle(
            orderId: 'RD-WB-IP',
            status: IncidentStatus::InProgress,
        );

        $workbench = app(AIWorkbenchService::class)->buildFromBundle(
            $incident,
            $this->withWaitingState($bundle, $this->lifecycleOnlyWaitingState(), serialMissing: false),
        );

        $this->assertSame('device_received', $workbench->scenario);
        $this->assertSame('Device received', $workbench->scenarioLabel);
    }

    public function test_waiting_scenarios_remain_stable(): void
    {
        $cases = [
            ['RD-WB-S1', WaitingReason::SerialNumber, true, 'waiting_for_serial', 'Waiting for serial'],
            ['RD-WB-S2', WaitingReason::Payment, false, 'payment_reminder', 'Payment reminder'],
            ['RD-WB-S3', WaitingReason::DevicePickup, false, 'pickup_scheduled', 'Pickup scheduled'],
        ];

        foreach ($cases as [$orderId, $reason, $serialMissing, $scenario, $label]) {
            [$incident, $bundle] = $this->createIncidentBundle(
                orderId: $orderId,
                serialMissing: $serialMissing,
                waitingReason: $reason,
            );

            $workbench = app(AIWorkbenchService::class)->buildFromBundle($incident, $bundle);

            $this->assertSame($scenario, $workbench->scenario, "Failed for {$reason->value}");
            $this->assertSame($label, $workbench->scenarioLabel, "Failed for {$reason->value}");
        }
    }

    public function test_resolved_and_closed_scenarios_remain_stable(): void
    {
        [$resolved, $resolvedBundle] = $this->createIncidentBundle(
            orderId: 'RD-WB-RES',
            status: IncidentStatus::Resolved,
        );
        $resolvedWorkbench = app(AIWorkbenchService::class)->buildFromBundle(
            $resolved,
            $this->withWaitingState($resolvedBundle, null, serialMissing: false),
        );
        $this->assertSame('ready_for_dispatch', $resolvedWorkbench->scenario);

        [$closed, $closedBundle] = $this->createIncidentBundle(
            orderId: 'RD-WB-CLS',
            status: IncidentStatus::Closed,
        );
        $closedWorkbench = app(AIWorkbenchService::class)->buildFromBundle(
            $closed,
            $this->withWaitingState($closedBundle, null, serialMissing: false),
        );
        $this->assertSame('repair_completed', $closedWorkbench->scenario);
    }

    public function test_rd3443709_shape_loads_executive_summary_workbench_and_ira_panel(): void
    {
        config(['ira.case_intelligence_engine.enabled' => true]);

        [$incident] = $this->createIncidentBundle(
            orderId: 'RD3443709',
            status: IncidentStatus::AwaitingProductDetails,
            serialMissing: false,
            assigned: true,
        );

        // Mirror production: no active wait; AI context still carries lifecycle-only card.
        $this->assertNull($incident->activeWaitingState);

        $snapshot = app(CaseIntelligenceEngine::class)->build($incident, true);
        $this->assertNotNull($snapshot);
        $this->assertNotSame('waiting_for_customer', $snapshot->workbench->scenario);

        $payload = app(Customer360Service::class)->executiveSummaryPayload($incident);
        $this->assertNotSame('', $payload['html']);
        $this->assertStringContainsString('data-ira-panel', $payload['html']);
        $this->assertStringContainsString('Action Center', $payload['html']);
        $this->assertStringContainsString('Executive Narrative', $payload['html']);
        $this->assertStringContainsString('IRA', $payload['html']);
    }

    /**
     * @return array{0: Incident, 1: AIIncidentBundle}
     */
    private function createIncidentBundle(
        bool $serialMissing = false,
        bool $warrantyExpired = false,
        bool $repeatTitle = false,
        ?WaitingReason $waitingReason = null,
        IncidentStatus $status = IncidentStatus::Open,
        string $orderId = 'RD-WB-001',
        bool $assigned = false,
    ): array {
        $actor = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => $orderId,
            'customer_name' => 'Workbench Customer',
            'customer_phone' => '9111000001',
            'customer_email' => 'workbench@example.com',
            'serial_number' => $serialMissing ? '' : '54SAXXC5514586',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        if ($warrantyExpired) {
            app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id, [
                'warranty' => 'Expired',
                'amc' => 'Not Available',
            ]);
        }

        if ($repeatTitle) {
            Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => app(IncidentReferenceService::class)->generate(),
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => 'Repeat sensor issue',
                'description' => 'Closed repeat.',
                'status' => IncidentStatus::Closed,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        }

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Repeat sensor issue',
            'description' => 'Open repeat.',
            'status' => $status,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $assigned ? $actor->id : null,
        ]);

        $reasonToPersist = $waitingReason ?? ($serialMissing ? WaitingReason::SerialNumber : null);
        if ($reasonToPersist !== null) {
            IncidentWaitingState::query()->create([
                'incident_id' => $incident->id,
                'waiting_reason' => $reasonToPersist,
                'started_at' => now()->subHour(),
                'sla_paused' => true,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        }

        $bundle = app(AIService::class)->buildBundle($incident);

        return [$incident->fresh(['order', 'activeWaitingState', 'assignee']), $bundle];
    }

    /**
     * @param  array<string, mixed>|null  $waitingState
     */
    private function withWaitingState(
        AIIncidentBundle $bundle,
        ?array $waitingState,
        ?bool $serialMissing = null,
    ): AIIncidentBundle {
        $context = AIContextFactory::make([
            'incidentId' => $bundle->context->incidentId,
            'incidentReference' => $bundle->context->incidentReference,
            'incidentTitle' => $bundle->context->incidentTitle,
            'incidentDescription' => $bundle->context->incidentDescription,
            'incidentStatus' => $bundle->context->incidentStatus,
            'serialMissing' => $serialMissing ?? $bundle->context->serialMissing,
            'serialNumber' => $bundle->context->serialNumber,
            'orderId' => $bundle->context->orderId,
            'waitingState' => $waitingState,
            'warrantyStatus' => $bundle->context->warrantyStatus,
            'customerName' => $bundle->context->customerName,
            'customerIntelligence' => $bundle->context->customerIntelligence,
            'deviceIntelligence' => $bundle->context->deviceIntelligence,
            'operationalIntelligence' => $bundle->context->operationalIntelligence,
            'businessIntelligence' => $bundle->context->businessIntelligence,
            'knowledge' => $bundle->knowledge,
        ]);

        return new AIIncidentBundle(
            response: $bundle->response,
            context: $context,
            knowledge: $bundle->knowledge,
            scopeCache: $bundle->scopeCache,
        );
    }

    /**
     * @return array{lifecycle_history: array<string, mixed>}
     */
    private function lifecycleOnlyWaitingState(string $waitingReasonLabel = 'Serial Number'): array
    {
        return [
            'lifecycle_history' => [
                'waiting_reason_label' => $waitingReasonLabel,
                'customer_waiting_since' => Carbon::parse('2026-07-08T07:10:58Z'),
                'customer_followup_sent_at' => Carbon::parse('2026-07-10T02:34:23Z'),
                'cleared_at' => Carbon::parse('2026-07-12T08:46:27Z'),
                'auto_closed' => false,
                'resolution_reason' => null,
                'resolution_reason_label' => null,
                'auto_closed_at' => null,
            ],
        ];
    }
}
