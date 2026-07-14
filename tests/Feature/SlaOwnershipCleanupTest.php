<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseAutomationStatus;
use App\Enums\ServiceCaseSlaStatus;
use App\Enums\WaitingReason;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\IncidentReferenceService;
use App\Services\IncidentWaitingStateService;
use App\Services\MissingSerial\MissingSerialAutomationService;
use App\Services\Operations\IraMemoryService;
use App\Services\Operations\OperationsSupportIntelligenceService;
use App\Services\Operations\SmartAssignmentService;
use App\Services\ServiceCaseAutomationStatusService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SlaOwnershipCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_automation_waiting_for_serial_status_still_classifies_without_pausing_until_request(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00'));

        [, $incident] = $this->createOpenIncidentWithoutSerial('RD-AUTO-WAIT');

        $status = app(ServiceCaseAutomationStatusService::class)->statusFor($incident);

        $this->assertSame(ServiceCaseAutomationStatus::WaitingForCustomerSerial, $status);
        $this->assertSame(ServiceCaseSlaStatus::WithinSla, $incident->fresh()->slaStatus());
        $this->assertNull(IncidentWaitingState::query()->where('incident_id', $incident->id)->first());
    }

    public function test_ensure_serial_waiting_state_pauses_sla(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 10:00:00'));

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        [, $incident] = $this->createOpenIncidentWithoutSerial('RD-PAUSE-1', $agent);

        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00'));

        app(IncidentWaitingStateService::class)->ensureSerialWaitingState($incident, $agent);

        $incident->refresh();

        $this->assertTrue($incident->hasSlaPaused());
        $this->assertSame(ServiceCaseSlaStatus::Paused, $incident->slaStatus());
        $this->assertDatabaseHas('incident_waiting_states', [
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber->value,
            'sla_paused' => true,
            'cleared_at' => null,
        ]);
    }

    public function test_serial_received_clears_waiting_state_and_resumes_sla_evaluation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 10:00:00'));

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        [, $incident] = $this->createOpenIncidentWithoutSerial('RD-CLEAR-1', $agent);

        app(IncidentWaitingStateService::class)->ensureSerialWaitingState($incident, $agent);

        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00'));

        $incident->order->update([
            'serial_number' => '7881953',
            'serial_entered_at' => now(),
            'serial_entered_by_user_id' => $agent->id,
        ]);

        app(MissingSerialAutomationService::class)->markCompletedIfApplicable(
            $incident->order->fresh(),
            'serial_resolved',
        );

        $incident->refresh();

        $this->assertFalse($incident->hasSlaPaused());
        $this->assertNotSame(ServiceCaseSlaStatus::Paused, $incident->slaStatus());
        $this->assertDatabaseMissing('incident_waiting_states', [
            'incident_id' => $incident->id,
            'cleared_at' => null,
        ]);
    }

    public function test_future_appointments_are_not_counted_as_urgent_workload(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00'));

        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->createScheduledIncident($agent, $creator, 'RD-FUTURE-ONLY', '2026-07-08');

        $metrics = app(SmartAssignmentService::class)->workloadMetrics($agent);

        $this->assertSame(0, $metrics['open_cases']);
        $this->assertSame(0, $metrics['scheduled_today']);
        $this->assertSame(1, $metrics['scheduled_future']);
        $this->assertSame(0, $metrics['total']);
        $this->assertSame(1, $metrics['scheduled_total']);
    }

    public function test_ira_snapshot_uses_todays_appointment_count_for_scheduled_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00'));

        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->createScheduledIncident($agent, $creator, 'RD-IRA-TODAY-1', '2026-07-06');
        $this->createScheduledIncident($agent, $creator, 'RD-IRA-TODAY-2', '2026-07-06');
        $this->createScheduledIncident($agent, $creator, 'RD-IRA-FUTURE-1', '2026-07-08');

        $operations = app(IraMemoryService::class)->collectSnapshotData()->operations;

        $this->assertSame(2, (int) ($operations['scheduled_today'] ?? 0));
        $this->assertSame(3, (int) ($operations['scheduled'] ?? 0));
    }

    public function test_service_and_hardware_sla_counts_are_reported_separately(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 12:00:00'));

        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $serviceIncident = $this->createPendingIncident($creator, 'RD-SERVICE-SLA', hoursAgo: 50);
        $hardwareIncident = $this->createPendingIncident($creator, 'RDE-SERVICE-SLA', hoursAgo: 50);

        $snapshot = DashboardSnapshot::load();
        $counts = $snapshot->slaCounts();

        $this->assertSame(2, $counts['overdue_cases']);
        $this->assertSame(1, $counts['service_overdue_cases']);
        $this->assertSame(1, $counts['hardware_overdue_cases']);
        $this->assertSame(0, $counts['service_warning_cases']);
        $this->assertSame(0, $counts['hardware_warning_cases']);
    }

    public function test_support_intelligence_operational_metrics_do_not_merge_sla_and_missed_appointments(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00'));

        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->createPendingIncident($creator, 'RD-SLA-RISK', hoursAgo: 50);

        $slaOnly = app(OperationsSupportIntelligenceService::class)->summary();

        $this->assertSame(1, $slaOnly->operationalMetrics['service_sla_risk']);
        $this->assertSame(0, $slaOnly->operationalMetrics['missed_appointments']);

        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->createScheduledIncident($agent, $creator, 'RD-MISSED-APT', '2026-07-05');

        $both = app(OperationsSupportIntelligenceService::class)->summary();

        $this->assertSame(1, $both->operationalMetrics['service_sla_risk']);
        $this->assertSame(1, $both->operationalMetrics['missed_appointments']);
        $this->assertArrayHasKey('service_overdue', $both->operationalMetrics);
        $this->assertArrayHasKey('missed_appointments', $both->operationalMetrics);
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createOpenIncidentWithoutSerial(string $orderId, ?User $actor = null): array
    {
        $actor ??= User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Missing serial',
            'description' => 'Missing serial case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);

        return [$actor, $incident->fresh(['order', 'activeWaitingState'])];
    }

    private function createScheduledIncident(
        User $assignee,
        User $creator,
        string $orderId,
        string $preferredDate,
    ): Incident {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Scheduled case',
            'description' => 'Scheduled case.',
            'status' => IncidentStatus::InProgress,
            'created_by' => $creator->id,
            'assigned_to_user_id' => $assignee->id,
        ]);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => $preferredDate,
            'preferred_time_slot' => 'morning',
            'phone_number' => '9999999999',
        ]);

        return $incident->fresh(['order', 'supportAppointments', 'activeWaitingState']);
    }

    private function createPendingIncident(
        User $creator,
        string $orderId,
        int $hoursAgo,
        ?User $assignee = null,
    ): Incident {
        $createdAt = now()->subHours($hoursAgo);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Pending SLA case',
            'description' => 'Pending SLA case.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'assigned_to_user_id' => $assignee?->id,
        ]);

        $incident->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();

        return $incident->fresh(['order', 'activeWaitingState', 'supportAppointments']);
    }
}
