<?php

namespace Tests\Unit\Operations;

use App\Data\Operations\OperationsInsightDTO;
use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationPolicyActionType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\Operations\OperationsInsightCategory;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Services\Operations\OperationsAdvisorService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OperationsAdvisorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        Cache::flush();
    }

    public function test_empty_dataset_returns_no_platform_insights(): void
    {
        $insights = app(OperationsAdvisorService::class)->platformInsights(useCache: false);

        $this->assertSame([], $insights);
    }

    public function test_detects_sla_risk_for_warning_and_overdue_cases(): void
    {
        $actor = User::factory()->create();
        $warningOrder = $this->createPendingOrder($actor, 'RD-SLA-WARN');
        $overdueOrder = $this->createPendingOrder($actor, 'RD-SLA-OVER');

        $warningIncident = $this->createPendingIncident($warningOrder, $actor, createdAt: now()->subHours(30));
        $overdueIncident = $this->createPendingIncident($overdueOrder, $actor, createdAt: now()->subHours(60));

        $insights = app(OperationsAdvisorService::class)->platformInsights(useCache: false);
        $slaInsight = collect($insights)->first(
            fn (OperationsInsightDTO $insight): bool => $insight->category === OperationsInsightCategory::SlaRisk
                && str_contains($insight->title, 'SLA breach'),
        );

        $this->assertNotNull($slaInsight);
        $this->assertSame(2, $slaInsight->supportingMetrics['overdue_cases'] + $slaInsight->supportingMetrics['warning_cases']);
        $this->assertCount(2, $slaInsight->affectedIncidents);
        $this->assertContains($warningIncident->display_reference, array_column($slaInsight->affectedIncidents, 'reference'));
        $this->assertContains($overdueIncident->display_reference, array_column($slaInsight->affectedIncidents, 'reference'));
    }

    public function test_detects_engineer_overload(): void
    {
        $actor = User::factory()->create();
        $engineer = User::factory()->create(['name' => 'Rahul Overloaded']);
        $otherEngineer = User::factory()->create(['name' => 'Balanced Engineer']);

        for ($index = 0; $index < 12; $index++) {
            $order = $this->createPendingOrder($actor, 'RD-OVERLOAD-'.$index, locked: true);
            Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => app(IncidentReferenceService::class)->generate(),
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => 'Overload case '.$index,
                'description' => 'Overload case.',
                'status' => IncidentStatus::Open,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'assigned_to_user_id' => $engineer->id,
            ]);
        }

        $balancedOrder = $this->createPendingOrder($actor, 'RD-OVERLOAD-BAL', locked: true);
        Incident::query()->create([
            'order_id' => $balancedOrder->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Balanced case',
            'description' => 'Balanced case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $otherEngineer->id,
        ]);

        for ($index = 0; $index < 2; $index++) {
            $order = $this->createPendingOrder($actor, 'RD-OVERLOAD-OTHER-'.$index, locked: true);
            Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => app(IncidentReferenceService::class)->generate(),
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => 'Other case '.$index,
                'description' => 'Other case.',
                'status' => IncidentStatus::Open,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'assigned_to_user_id' => $otherEngineer->id,
            ]);
        }

        $insights = app(OperationsAdvisorService::class)->platformInsights(useCache: false);
        $overloadInsight = collect($insights)->first(
            fn (OperationsInsightDTO $insight): bool => $insight->category === OperationsInsightCategory::EngineerWorkload
                && str_contains($insight->title, 'Rahul Overloaded'),
        );

        $this->assertNotNull($overloadInsight);
        $this->assertSame(12, $overloadInsight->supportingMetrics['assigned_cases']);
    }

    public function test_detects_notification_degradation_from_whatsapp_failures(): void
    {
        $actor = User::factory()->create();
        $order = $this->createPendingOrder($actor, 'RD-NOTIFY-FAIL');
        $incident = $this->createPendingIncident($order, $actor);

        app(AuditLogService::class)->log(
            userId: $actor->id,
            event: NotificationAuditTrailService::EVENT_DISPATCHED,
            auditable: $incident,
            newValues: [
                'notification_type' => 'request_serial_number',
                'source' => 'manual',
                'trigger_source' => 'manual',
                'aggregate_success' => false,
                'aggregate_message' => 'Notification failed',
                'channel_results' => [
                    [
                        'channel' => 'whatsapp',
                        'status' => 'failed',
                        'success' => false,
                        'retryable' => true,
                        'message' => 'Interakt API timeout',
                        'timestamp' => now()->toIso8601String(),
                        'duration_ms' => 1200,
                    ],
                ],
            ],
        );

        $insights = app(OperationsAdvisorService::class)->platformInsights(useCache: false);
        $notificationInsight = collect($insights)->first(
            fn (OperationsInsightDTO $insight): bool => $insight->category === OperationsInsightCategory::NotificationHealth
                && str_contains($insight->title, 'WhatsApp delivery dropped'),
        );

        $this->assertNotNull($notificationInsight);
        $this->assertSame(1, $notificationInsight->supportingMetrics['whatsapp_failed_today']);
    }

    public function test_detects_amc_opportunities(): void
    {
        $actor = User::factory()->create();
        $phone = '9000000001';

        $orderOne = $this->createPendingOrder($actor, 'RD-AMC-1', phone: $phone);
        $orderTwo = Order::query()->create([
            'order_id' => 'RD-AMC-2',
            'customer_name' => 'AMC Customer',
            'customer_phone' => $phone,
            'serial_number' => 'FPSPL1142XX',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($orderOne->id, [
            'warranty' => 'Expired',
            'amc' => 'Not Available',
        ]);
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($orderTwo->id, [
            'warranty' => 'Expired',
            'amc' => 'Not Available',
        ]);

        $this->createPendingIncident($orderOne, $actor);

        $insights = app(OperationsAdvisorService::class)->platformInsights(useCache: false);
        $amcInsight = collect($insights)->first(
            fn (OperationsInsightDTO $insight): bool => $insight->category === OperationsInsightCategory::RevenueOpportunity
                && str_contains($insight->title, 'AMC opportunit'),
        );

        $this->assertNotNull($amcInsight);
        $this->assertSame(1, $amcInsight->supportingMetrics['amc_opportunity_count']);
    }

    public function test_detects_repeat_customer_complaints(): void
    {
        $actor = User::factory()->create();
        $phone = '9000000002';

        foreach (['RD-REPEAT-1', 'RD-REPEAT-2'] as $orderId) {
            $order = $this->createPendingOrder($actor, $orderId, phone: $phone, locked: true);
            Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => app(IncidentReferenceService::class)->generate(),
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => 'Repeat complaint',
                'description' => 'Repeat complaint.',
                'status' => IncidentStatus::Open,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        }

        $insights = app(OperationsAdvisorService::class)->platformInsights(useCache: false);
        $repeatInsight = collect($insights)->first(
            fn (OperationsInsightDTO $insight): bool => $insight->category === OperationsInsightCategory::CustomerRisk
                && str_contains($insight->title, 'repeat complaint'),
        );

        $this->assertNotNull($repeatInsight);
        $this->assertSame(1, $repeatInsight->supportingMetrics['repeat_customer_count']);
    }

    public function test_platform_insights_are_cached(): void
    {
        Cache::flush();

        $service = app(OperationsAdvisorService::class);
        $first = $service->platformInsights();
        $this->assertTrue(Cache::has('operations:advisor:platform'));

        $second = $service->platformInsights();
        $this->assertSame($first, $second);
    }

    public function test_incident_insights_include_sla_repeat_and_amc_advice(): void
    {
        $actor = User::factory()->create();
        $phone = '9000000003';

        $orderOne = $this->createPendingOrder($actor, 'RD-INC-1', phone: $phone);
        $orderTwo = Order::query()->create([
            'order_id' => 'RD-INC-2',
            'customer_name' => 'Incident Advisor Customer',
            'customer_phone' => $phone,
            'serial_number' => 'FPSPL1143XX',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($orderOne->id, [
            'warranty' => 'Expired',
            'amc' => 'Not Available',
        ]);

        $closedIncident = Incident::query()->create([
            'order_id' => $orderOne->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Same issue again',
            'description' => 'Closed repeat.',
            'status' => IncidentStatus::Closed,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $orderOne->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Same issue again',
            'description' => 'Open repeat.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'high_priority' => true,
        ]);
        $incident->created_at = now()->subHours(10);
        $incident->updated_at = now()->subHours(10);
        $incident->saveQuietly();
        $incident->load('order');

        $insights = app(OperationsAdvisorService::class)->incidentInsights($incident);
        $titles = collect($insights)->map(fn (OperationsInsightDTO $insight): string => $insight->title)->all();

        $this->assertContains('High SLA Risk', $titles);
        $this->assertContains('Repeat Failure Risk', $titles);
        $this->assertContains('AMC Opportunity', $titles);
        $this->assertContains('Escalation Risk', $titles);
    }

    private function createPendingOrder(
        User $actor,
        string $orderId,
        ?string $phone = null,
        bool $locked = false,
    ): Order {
        return Order::query()->create([
            'order_id' => $orderId,
            'customer_name' => 'Advisor Customer',
            'customer_phone' => $phone ?? '9000000'.random_int(100, 999),
            'serial_number' => 'FPSPL1140XX',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => $locked ? 'TXN-'.$orderId : null,
            'status' => 'active',
            'created_by' => $actor->id,
        ]);
    }

    private function createPendingIncident(
        Order $order,
        User $actor,
        ?\Illuminate\Support\Carbon $createdAt = null,
    ): Incident {
        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Advisor case',
            'description' => 'Advisor case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        if ($createdAt !== null) {
            $incident->created_at = $createdAt;
            $incident->updated_at = $createdAt;
            $incident->saveQuietly();
        }

        return $incident->fresh(['order']);
    }
}
