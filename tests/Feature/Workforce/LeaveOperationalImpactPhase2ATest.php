<?php

namespace Tests\Feature\Workforce;

use App\Enums\AssignmentOrigin;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\LeaveRequestStatus;
use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Operations\LeaveOperationalImpactService;
use App\Services\Operations\LeaveRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LeaveOperationalImpactPhase2ATest extends TestCase
{
    use RefreshDatabase;

    private LeaveRequestService $leaveService;

    private LeaveOperationalImpactService $impactService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->leaveService = app(LeaveRequestService::class);
        $this->impactService = app(LeaveOperationalImpactService::class);

        Carbon::setTestNow(Carbon::parse('2026-08-03 11:00:00', 'Asia/Kolkata'));
        config([
            'workforce.leave_approver.email' => 'shipra@radiumbox.com',
            'workforce_calendar.retroactive_leave_days' => 14,
            'service_case_assignment.escalation.level_1_email' => 'shubhanshi@radiumbox.com',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_employee_with_workload_shows_warning_and_counts(): void
    {
        $shipra = $this->createShipra();
        $agent = $this->createAgent('Workload Agent');

        $this->createOpenCase($shipra, $agent, IncidentStatus::Open);
        $this->createOpenCase($shipra, $agent, IncidentStatus::AwaitingProductDetails);
        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-04',
            'end_date' => '2026-08-04',
            'reason' => 'Workload leave',
        ]);

        $impact = $this->impactService->forLeaveRequest($leave);

        $this->assertNotNull($impact);
        $this->assertTrue($impact->hasWorkload);
        $this->assertSame(
            'Approving leave will NOT automatically redistribute this work.',
            $impact->warningMessage,
        );
        $this->assertSame(2, $this->sectionCount($impact->sections, 'open_cases'));
        $this->assertSame(1, $this->sectionCount($impact->sections, 'awaiting_product_details'));

        $this->actingAs($shipra)
            ->get(route('leave-requests.show', $leave))
            ->assertOk()
            ->assertSee('Operational Impact Analysis')
            ->assertSee('Approving leave will NOT automatically redistribute this work.')
            ->assertSee('Open service cases')
            ->assertSee('Approve Leave')
            ->assertSee('Reject Leave')
            ->assertSee('Open Assigned Cases')
            ->assertSee('Open Appointments')
            ->assertSee('Open Ready Queue');
    }

    public function test_employee_with_no_workload_shows_clear_message(): void
    {
        $shipra = $this->createShipra();
        $agent = $this->createAgent('Idle Agent');

        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-05',
            'end_date' => '2026-08-05',
            'reason' => 'Clear leave',
        ]);

        $impact = $this->impactService->forLeaveRequest($leave);

        $this->assertNotNull($impact);
        $this->assertFalse($impact->hasWorkload);
        $this->assertSame('No operational workload detected.', $impact->warningMessage);
        $this->assertSame(0, $this->sectionCount($impact->sections, 'open_cases'));

        $this->actingAs($shipra)
            ->get(route('leave-requests.show', $leave))
            ->assertOk()
            ->assertSee('No operational workload detected.');
    }

    public function test_open_cases_only_section(): void
    {
        $actor = $this->createShipra();
        $agent = $this->createAgent('Cases Only');

        $this->createOpenCase($actor, $agent, IncidentStatus::Open);
        $this->createOpenCase($actor, $agent, IncidentStatus::Open);
        $this->createOpenCase($actor, $agent, IncidentStatus::InProgress);

        $impact = $this->impactService->forUser($agent);

        $this->assertTrue($impact->hasWorkload);
        $this->assertSame(3, $this->sectionCount($impact->sections, 'open_cases'));
        $this->assertSame(0, $this->sectionCount($impact->sections, 'scheduled_appointments'));
    }

    public function test_appointments_only_section(): void
    {
        $actor = $this->createShipra();
        $agent = $this->createAgent('Appt Only');
        $incidentA = $this->createOpenCase($actor, $agent, IncidentStatus::Open);
        $incidentB = $this->createOpenCase($actor, $agent, IncidentStatus::Open);

        SupportAppointment::query()->create([
            'incident_id' => $incidentA->id,
            'preferred_date' => '2026-08-10',
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9999999999',
            'normalized_phone' => '9999999999',
            'status' => SupportAppointmentStatus::Scheduled,
        ]);

        SupportAppointment::query()->create([
            'incident_id' => $incidentB->id,
            'preferred_date' => '2026-08-03',
            'preferred_time_slot' => SupportAppointmentTimeSlot::Evening,
            'phone_number' => '8888888888',
            'normalized_phone' => '8888888888',
            'status' => SupportAppointmentStatus::Scheduled,
        ]);

        $impact = $this->impactService->forUser($agent);

        $this->assertSame(2, $this->sectionCount($impact->sections, 'scheduled_appointments'));
        $this->assertSame(1, $this->sectionCount($impact->sections, 'todays_appointments'));
        $this->assertSame(2, $this->sectionCount($impact->sections, 'callbacks'));
    }

    public function test_ready_queue_only_counts_visible_ready_cases(): void
    {
        $actor = $this->createShipra();
        $agent = $this->createAgent('Ready Only');
        $this->createOpenCase($actor, $agent, IncidentStatus::Open, AssignmentOrigin::Auto);

        $impact = $this->impactService->forUser($agent);
        $ready = $this->sectionCount($impact->sections, 'ready_queue');

        $this->assertGreaterThanOrEqual(1, $ready);
        $this->assertSame('No', $this->sectionDisplay($impact->sections, 'escalation_ownership'));
    }

    public function test_escalation_owner_shows_yes(): void
    {
        $specialist = User::factory()->create([
            'email' => 'shubhanshi@radiumbox.com',
            'name' => 'Shubhanshi Rathore',
            'is_active' => true,
        ]);
        $specialist->assignRole(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST);

        $impact = $this->impactService->forUser($specialist);

        $this->assertSame('YES', $this->sectionDisplay($impact->sections, 'escalation_ownership'));
        $this->assertTrue($impact->hasWorkload);
    }

    public function test_approve_still_works_with_impact_present(): void
    {
        $shipra = $this->createShipra();
        $agent = $this->createAgent('Approve Still Works');
        $this->createOpenCase($shipra, $agent, IncidentStatus::Open);

        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-06',
            'end_date' => '2026-08-06',
            'reason' => 'Approve with workload',
        ]);

        $this->actingAs($shipra)
            ->post(route('leave-requests.approve', $leave), [
                'review_notes' => 'Approved after impact review',
            ])
            ->assertRedirect(route('leave-requests.show', $leave));

        $this->assertSame(LeaveRequestStatus::Approved, $leave->fresh()->status);
        $this->assertSame($shipra->id, $leave->fresh()->reviewed_by);
    }

    public function test_reject_still_works_with_impact_present(): void
    {
        $shipra = $this->createShipra();
        $agent = $this->createAgent('Reject Still Works');
        $this->createOpenCase($shipra, $agent, IncidentStatus::Open);

        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-07',
            'end_date' => '2026-08-07',
            'reason' => 'Reject with workload',
        ]);

        $this->actingAs($shipra)
            ->post(route('leave-requests.reject', $leave), [
                'review_notes' => 'Rejected after impact review',
            ])
            ->assertRedirect(route('leave-requests.show', $leave));

        $this->assertSame(LeaveRequestStatus::Rejected, $leave->fresh()->status);
    }

    public function test_non_designated_reviewer_does_not_see_impact_panel(): void
    {
        $this->createShipra();
        $otherOps = User::factory()->create([
            'email' => 'other-ops@radiumbox.com',
            'is_active' => true,
        ]);
        $otherOps->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $agent = $this->createAgent('No Impact Viewer');
        $this->createOpenCase($otherOps, $agent, IncidentStatus::Open);

        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-08',
            'end_date' => '2026-08-08',
            'reason' => 'Hidden impact',
        ]);

        // Other ops can view via leave-requests.view + review permission, but is not Leave Authority.
        $this->actingAs($otherOps)
            ->get(route('leave-requests.show', $leave))
            ->assertOk()
            ->assertDontSee('Operational Impact Analysis')
            ->assertDontSee('Approve Leave');
    }

    public function test_impact_is_read_only_and_does_not_change_assignment(): void
    {
        $actor = $this->createShipra();
        $agent = $this->createAgent('Read Only Impact');
        $incident = $this->createOpenCase($actor, $agent, IncidentStatus::Open, AssignmentOrigin::Manual);

        $beforeAssignee = $incident->fresh()->assigned_to_user_id;
        $beforeOrigin = $incident->fresh()->assignment_origin;

        $this->impactService->forUser($agent);

        $incident->refresh();
        $this->assertSame($beforeAssignee, $incident->assigned_to_user_id);
        $this->assertSame($beforeOrigin, $incident->assignment_origin);
    }

    private function createShipra(): User
    {
        $shipra = User::factory()->create([
            'email' => 'shipra@radiumbox.com',
            'name' => 'Shipra',
            'is_active' => true,
        ]);
        $shipra->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $shipra->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        return $shipra;
    }

    private function createAgent(string $name): User
    {
        $agent = User::factory()->create([
            'name' => $name,
            'is_active' => true,
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $agent;
    }

    private function createOpenCase(
        User $actor,
        User $assignee,
        IncidentStatus $status,
        AssignmentOrigin $origin = AssignmentOrigin::Auto,
    ): Incident {
        $order = Order::query()->create([
            'order_id' => 'RD-L2A-'.uniqid(),
            'serial_number' => 'SN-L2A-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Phase 2A case',
            'description' => 'Phase 2A operational impact case.',
            'status' => $status,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $assignee->id,
            'assignment_origin' => $origin,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function sectionCount(array $sections, string $key): int
    {
        foreach ($sections as $section) {
            if (($section['key'] ?? null) === $key) {
                return (int) ($section['count'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function sectionDisplay(array $sections, string $key): string
    {
        foreach ($sections as $section) {
            if (($section['key'] ?? null) === $key) {
                return (string) ($section['display'] ?? '');
            }
        }

        return '';
    }
}
