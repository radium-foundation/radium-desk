<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\RemarkOrigin;
use App\Enums\TeamAvailabilityStatus;
use App\Models\ApprovalNumber;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\Remark;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\ApprovalNumberService;
use App\Services\CommunicationActions\CommunicationActionLifecycleAuditService;
use App\Services\Dashboard\TeamActivityPanelService;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Services\Operations\PresenceEngineService;
use App\Services\ServiceCaseAutomationMonitorService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTeamActivityCasesWorkedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['dashboard-team-activity.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-07-06 11:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_twenty_actions_on_one_reference_no_count_as_one_case_worked(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident] = $this->createIncident($agent);

        foreach (range(1, 20) as $index) {
            AuditLog::query()->create([
                'user_id' => $agent->id,
                'event' => 'service_case.status_changed',
                'auditable_type' => $incident->getMorphClass(),
                'auditable_id' => $incident->id,
                'new_values' => ['status' => 'in_progress'],
                'created_at' => now()->subMinutes($index),
            ]);
        }

        $this->assertSame(1, $this->todayCountFor($agent));
    }

    public function test_two_users_on_same_reference_no_each_receive_one_case_worked(): void
    {
        $agentA = $this->createTrackedAgent('Agent A');
        $agentB = $this->createTrackedAgent('Agent B');
        [$incident] = $this->createIncident($agentA);

        AuditLog::query()->create([
            'user_id' => $agentA->id,
            'event' => 'service_case.status_changed',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => now()->subMinute(),
        ]);

        AuditLog::query()->create([
            'user_id' => $agentB->id,
            'event' => 'service_case.escalated',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => now(),
        ]);

        $this->assertSame(1, $this->todayCountFor($agentA));
        $this->assertSame(1, $this->todayCountFor($agentB));
    }

    public function test_thirty_five_serial_numbers_on_one_reference_no_count_as_one_case_worked(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident, $order] = $this->createIncident($agent);

        foreach (range(1, 35) as $index) {
            AuditLog::query()->create([
                'user_id' => $agent->id,
                'event' => 'serial.assigned',
                'auditable_type' => $order->getMorphClass(),
                'auditable_id' => $order->id,
                'new_values' => [
                    'serial_number' => 'SN-'.$index,
                    'incident_id' => $incident->id,
                ],
                'created_at' => now()->subMinutes(35 - $index),
            ]);
        }

        $this->assertSame(1, $this->todayCountFor($agent));
    }

    public function test_multiple_manual_remarks_on_one_reference_no_count_as_one_case_worked(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident] = $this->createIncident($agent);

        foreach (range(1, 20) as $index) {
            $remark = Remark::query()->create([
                'user_id' => $agent->id,
                'remarkable_type' => $incident->getMorphClass(),
                'remarkable_id' => $incident->id,
                'body' => 'Manual remark '.$index,
            ]);

            AuditLog::query()->create([
                'user_id' => $agent->id,
                'event' => 'created',
                'auditable_type' => $remark->getMorphClass(),
                'auditable_id' => $remark->id,
                'new_values' => ['origin' => RemarkOrigin::Manual->value],
                'created_at' => now()->subMinutes($index),
            ]);
        }

        $this->assertSame(1, $this->todayCountFor($agent));
    }

    public function test_system_generated_events_do_not_affect_cases_worked_kpi(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident] = $this->createIncident($agent);

        $ignoredEvents = [
            CommunicationActionLifecycleAuditService::EVENT,
            NotificationAuditTrailService::EVENT_DISPATCHED,
            ServiceCaseAutomationMonitorService::EVENT_VALIDATION_PASSED,
            'user.availability_changed',
            'notification.skipped',
            'serial.corrected_by_ira',
        ];

        foreach ($ignoredEvents as $event) {
            foreach (range(1, 10) as $index) {
                AuditLog::query()->create([
                    'user_id' => $agent->id,
                    'event' => $event,
                    'auditable_type' => $incident->getMorphClass(),
                    'auditable_id' => $incident->id,
                    'created_at' => now()->subMinutes($index),
                ]);
            }
        }

        $this->assertSame(0, $this->todayCountFor($agent));
    }

    public function test_refund_approval_maps_to_incident_for_cases_worked(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident, $order] = $this->createIncident($agent);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => 'RF-1001',
            'amount' => 100,
            'reason' => 'Test refund',
            'status' => 'pending',
            'requested_by' => $agent->id,
        ]);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'refund.approved',
            'auditable_type' => $refund->getMorphClass(),
            'auditable_id' => $refund->id,
            'created_at' => now(),
        ]);

        $this->assertSame(1, $this->todayCountFor($agent));
    }

    public function test_approval_number_linked_to_incident_counts_once_per_reference_no(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident] = $this->createIncident($agent);

        $approval = ApprovalNumber::query()->create([
            'approval_number' => 'APR-0001',
            'description' => 'Batch approval',
            'created_by' => $agent->id,
        ]);

        $approval->incidents()->attach([
            $incident->id => ['linked_by' => $agent->id],
        ]);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => ApprovalNumberService::EVENT_SUBMITTED,
            'auditable_type' => $approval->getMorphClass(),
            'auditable_id' => $approval->id,
            'new_values' => [
                'count' => 1,
                'approval_ids' => [$approval->id],
            ],
            'created_at' => now(),
        ]);

        $this->assertSame(1, $this->todayCountFor($agent));
    }

    public function test_two_distinct_reference_numbers_count_as_two_cases_worked(): void
    {
        $agent = $this->createTrackedAgent();
        [$incidentA] = $this->createIncident($agent);
        [$incidentB] = $this->createIncident($agent);

        foreach ([$incidentA, $incidentB] as $incident) {
            AuditLog::query()->create([
                'user_id' => $agent->id,
                'event' => 'service_case.status_changed',
                'auditable_type' => $incident->getMorphClass(),
                'auditable_id' => $incident->id,
                'created_at' => now(),
            ]);
        }

        $this->assertSame(2, $this->todayCountFor($agent));
    }

    private function todayCountFor(User $agent): int
    {
        $panel = app(TeamActivityPanelService::class)->build();
        $row = collect($panel->agents)->firstWhere('id', $agent->id);

        return $row?->todayCount ?? 0;
    }

    private function createTrackedAgent(string $name = 'Tracked Agent'): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        app(PresenceEngineService::class)->startSession($user->fresh(['workSchedule', 'roles']));

        return $user->fresh(['workSchedule', 'roles']);
    }

    /**
     * @return array{0: Incident, 1: Order}
     */
    private function createIncident(User $user): array
    {
        $order = Order::query()->create([
            'order_id' => 'RD'.random_int(1000000, 9999999),
            'serial_number' => 'SN-CASES',
            'customer_name' => 'Cases Worked Customer',
            'product_name' => 'RBX 110',
            'device_model' => 'RBX 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Cases worked test case',
            'description' => 'Cases worked test case.',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        return [$incident, $order];
    }
}
