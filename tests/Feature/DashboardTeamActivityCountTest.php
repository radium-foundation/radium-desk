<?php

namespace Tests\Feature;

use App\Models\ApprovalNumber;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Remark;
use App\Models\User;
use App\Services\ApprovalNumberService;
use App\Services\CommunicationActions\CommunicationActionLifecycleAuditService;
use App\Services\Dashboard\TeamActivityPanelService;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Services\Operations\PresenceEngineService;
use App\Services\ServiceCaseAutomationMonitorService;
use App\Enums\IncidentSource;
use App\Enums\TeamAvailabilityStatus;
use App\Models\TeamMemberWorkSchedule;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTeamActivityCountTest extends TestCase
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

    public function test_assignment_counts_as_one_operational_activity(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident] = $this->createIncident($agent);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'service_case.assigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => now(),
        ]);

        $this->assertSame(1, $this->todayCountFor($agent));
    }

    public function test_status_change_counts_as_one_operational_activity(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident] = $this->createIncident($agent);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'service_case.status_changed',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => now(),
        ]);

        $this->assertSame(1, $this->todayCountFor($agent));
    }

    public function test_remark_added_counts_as_one_operational_activity(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident] = $this->createIncident($agent);
        $remark = Remark::query()->create([
            'user_id' => $agent->id,
            'remarkable_type' => $incident->getMorphClass(),
            'remarkable_id' => $incident->id,
            'body' => 'Follow up with customer.',
        ]);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'created',
            'auditable_type' => $remark->getMorphClass(),
            'auditable_id' => $remark->id,
            'new_values' => ['origin' => 'manual'],
            'created_at' => now(),
        ]);

        $this->assertSame(1, $this->todayCountFor($agent));
    }

    public function test_approval_number_submission_with_linked_incident_counts_as_one_case_worked(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident] = $this->createIncident($agent);

        $approval = ApprovalNumber::query()->create([
            'approval_number' => 'APR-0001',
            'description' => 'Batch save',
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
            'new_values' => ['count' => 10],
            'created_at' => now(),
        ]);

        $this->assertSame(1, $this->todayCountFor($agent));
    }

    public function test_two_approval_submissions_on_two_incidents_count_as_two_cases_worked(): void
    {
        $agent = $this->createTrackedAgent();
        [$incidentA] = $this->createIncident($agent);
        [$incidentB] = $this->createIncident($agent);
        $submittedAt = now();

        foreach ([[$incidentA, 'APR-A'], [$incidentB, 'APR-B']] as [$incident, $number]) {
            $approval = ApprovalNumber::query()->create([
                'approval_number' => $number,
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
                'created_at' => $submittedAt,
            ]);
        }

        $this->assertSame(2, $this->todayCountFor($agent));
    }

    public function test_slow_approval_submission_spanning_multiple_seconds_counts_linked_incident_once(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident] = $this->createIncident($agent);
        $approvalMorph = (new ApprovalNumber)->getMorphClass();
        $submittedAt = now();

        $approval = ApprovalNumber::query()->create([
            'approval_number' => 'APR-0001',
            'created_by' => $agent->id,
        ]);
        $approval->incidents()->attach([$incident->id => ['linked_by' => $agent->id]]);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => ApprovalNumberService::EVENT_SUBMITTED,
            'auditable_type' => $approvalMorph,
            'auditable_id' => $approval->id,
            'new_values' => [
                'count' => 10,
                'approval_ids' => range(1, 10),
            ],
            'created_at' => $submittedAt,
        ]);

        foreach (range(1, 10) as $index) {
            AuditLog::query()->create([
                'user_id' => $agent->id,
                'event' => 'created',
                'auditable_type' => $approvalMorph,
                'auditable_id' => $index,
                'created_at' => $submittedAt->copy()->addSeconds($index - 1),
            ]);
        }

        $this->assertSame(1, $this->todayCountFor($agent));
    }

    public function test_communication_lifecycle_events_are_ignored_for_today_count(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident] = $this->createIncident($agent);

        foreach (range(1, 50) as $index) {
            AuditLog::query()->create([
                'user_id' => $agent->id,
                'event' => CommunicationActionLifecycleAuditService::EVENT,
                'auditable_type' => $incident->getMorphClass(),
                'auditable_id' => $incident->id,
                'new_values' => ['status' => 'opened', 'action_key' => 'review_request'],
                'created_at' => now()->subMinutes($index),
            ]);
        }

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'service_case.assigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => now(),
        ]);

        $this->assertSame(1, $this->todayCountFor($agent));
    }

    public function test_notification_dispatched_events_are_ignored_for_today_count(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident] = $this->createIncident($agent);

        foreach (range(1, 25) as $index) {
            AuditLog::query()->create([
                'user_id' => $agent->id,
                'event' => NotificationAuditTrailService::EVENT_DISPATCHED,
                'auditable_type' => $incident->getMorphClass(),
                'auditable_id' => $incident->id,
                'created_at' => now()->subMinutes($index),
            ]);
        }

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'service_case.status_changed',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => now(),
        ]);

        $this->assertSame(1, $this->todayCountFor($agent));
    }

    public function test_automation_and_system_events_are_ignored_for_today_count(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident] = $this->createIncident($agent);

        $ignoredEvents = [
            ServiceCaseAutomationMonitorService::EVENT_VALIDATION_PASSED,
            'incoming_email.received',
            'user.availability_changed',
            'service_case.customer_waiting_auto_closed',
            'serial.corrected_by_ira',
            'notification.skipped',
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

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'service_case.escalated',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => now(),
        ]);

        $this->assertSame(1, $this->todayCountFor($agent));
    }

    public function test_refund_approval_maps_to_incident_for_cases_worked(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident, $order] = $this->createIncident($agent);

        $refund = \App\Models\RefundRequest::query()->create([
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
            'event' => 'created',
            'auditable_type' => $refund->getMorphClass(),
            'auditable_id' => $refund->id,
            'created_at' => now(),
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

    private function todayCountFor(User $agent): int
    {
        $panel = app(TeamActivityPanelService::class)->build();
        $row = collect($panel->agents)->firstWhere('id', $agent->id);

        return $row?->todayCount ?? 0;
    }

    private function createTrackedAgent(): User
    {
        $user = User::factory()->create([
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
            'serial_number' => 'SN-COUNT',
            'customer_name' => 'Count Test Customer',
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
            'title' => 'Team activity count test case',
            'description' => 'Team activity count test case.',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        return [$incident, $order];
    }
}
