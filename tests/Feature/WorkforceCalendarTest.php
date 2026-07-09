<?php

namespace Tests\Feature;

use App\Enums\CompanyHolidayType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\LeaveRequestStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Enums\TeamAvailabilityStatus;
use App\Enums\WorkCalendarDayStatus;
use App\Models\CompanyHoliday;
use App\Models\Incident;
use App\Models\LeaveRequest;
use App\Models\Order;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Operations\LeaveRequestService;
use App\Services\Operations\PresenceEngineService;
use App\Services\Operations\SmartAssignmentService;
use App\Services\Operations\TeamAvailabilityOverviewService;
use App\Services\Operations\WorkCalendarService;
use App\Services\SupportAppointmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WorkforceCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['smart_assignment.enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_user_working_today_can_receive_assignment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent('Working Agent', TeamAvailabilityStatus::Available);
        $incident = $this->createUnassignedIncident();

        $this->bookAppointment($incident);

        $incident->refresh();
        $this->assertSame($agent->id, $incident->assigned_to_user_id);
        $this->assertTrue(app(SmartAssignmentService::class)->isEligible($agent));
    }

    public function test_weekly_off_user_is_skipped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $offAgent = $this->createScheduledAgent('Weekly Off Agent', TeamAvailabilityStatus::Available, [Carbon::MONDAY]);
        $workingAgent = $this->createScheduledAgent('Working Agent', TeamAvailabilityStatus::Available, [Carbon::SUNDAY]);

        $this->assertFalse(app(SmartAssignmentService::class)->isEligible($offAgent));
        $this->assertTrue(app(SmartAssignmentService::class)->isEligible($workingAgent));

        $incident = $this->createUnassignedIncident();
        $this->bookAppointment($incident);

        $incident->refresh();
        $this->assertSame($workingAgent->id, $incident->assigned_to_user_id);
        $this->assertNotSame($offAgent->id, $incident->assigned_to_user_id);
    }

    public function test_approved_leave_user_is_skipped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $onLeaveAgent = $this->createScheduledAgent('Leave Agent', TeamAvailabilityStatus::Available);
        $workingAgent = $this->createScheduledAgent('Available Agent', TeamAvailabilityStatus::Available);

        LeaveRequest::query()->create([
            'user_id' => $onLeaveAgent->id,
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-06',
            'reason' => 'Family event',
            'status' => LeaveRequestStatus::Approved,
        ]);

        $this->assertFalse(app(SmartAssignmentService::class)->isEligible($onLeaveAgent));

        $incident = $this->createUnassignedIncident();
        $this->bookAppointment($incident);

        $incident->refresh();
        $this->assertSame($workingAgent->id, $incident->assigned_to_user_id);
    }

    public function test_pending_leave_does_not_block_assignment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent('Pending Leave Agent', TeamAvailabilityStatus::Available);

        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-06',
            'reason' => 'Awaiting approval',
            'status' => LeaveRequestStatus::Pending,
        ]);

        $this->assertTrue(app(SmartAssignmentService::class)->isEligible($agent));

        $incident = $this->createUnassignedIncident();
        $this->bookAppointment($incident);

        $incident->refresh();
        $this->assertSame($agent->id, $incident->assigned_to_user_id);
    }

    public function test_holiday_blocks_assignment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00', 'Asia/Kolkata'));

        $this->createScheduledAgent('Holiday Agent', TeamAvailabilityStatus::Available);

        CompanyHoliday::query()->create([
            'holiday_date' => '2026-08-15',
            'name' => 'Independence Day',
            'type' => CompanyHolidayType::National,
        ]);

        $incident = $this->createUnassignedIncident('RD-HOLIDAY-1');
        app(SupportAppointmentService::class)->book($incident, [
            'preferred_date' => '2026-08-17',
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
            'additional_notes' => 'Need remote support.',
        ]);

        $incident->refresh();
        $this->assertNull($incident->assigned_to_user_id);
        $this->assertFalse(app(WorkCalendarService::class)->isEligibleForAssignment(
            User::query()->role(RolePermissionSeeder::SUPPORT_TEAM_ROLES)->first(),
        ));
    }

    public function test_correct_leave_approval_hierarchy(): void
    {
        $supportAgent = User::factory()->create();
        $supportAgent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        $operationsAdmin = User::factory()->create();
        $operationsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $owner = User::factory()->create();
        $owner->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $supportLeave = app(LeaveRequestService::class)->submit($supportAgent, [
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-12',
            'reason' => 'Personal leave',
        ]);

        $operationsLeave = app(LeaveRequestService::class)->submit($operationsAdmin, [
            'start_date' => '2026-07-15',
            'end_date' => '2026-07-16',
            'reason' => 'Operations leave',
        ]);

        $leaveService = app(LeaveRequestService::class);

        $this->assertTrue($leaveService->canReview($operationsAdmin, $supportLeave));
        $this->assertFalse($leaveService->canReview($supportAgent, $supportLeave));

        $this->assertTrue($leaveService->canReview($owner, $operationsLeave));
        $this->assertFalse($leaveService->canReview($operationsAdmin, $operationsLeave));

        $leaveService->approve($supportLeave, $operationsAdmin);
        $leaveService->approve($operationsLeave, $owner);

        $this->assertSame(LeaveRequestStatus::Approved, $supportLeave->fresh()->status);
        $this->assertSame(LeaveRequestStatus::Approved, $operationsLeave->fresh()->status);
    }

    public function test_lunch_time_does_not_mark_unavailable_permanently(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent('Lunch Agent', TeamAvailabilityStatus::Available);
        $workCalendar = app(WorkCalendarService::class);

        $this->assertTrue($workCalendar->isEligibleForAssignment($agent));

        Carbon::setTestNow(Carbon::parse('2026-07-06 13:45:00', 'Asia/Kolkata'));
        $this->assertFalse($workCalendar->isEligibleForAssignment($agent->fresh()));

        Carbon::setTestNow(Carbon::parse('2026-07-06 14:05:00', 'Asia/Kolkata'));
        $this->assertTrue($workCalendar->isEligibleForAssignment($agent->fresh()));
    }

    public function test_existing_availability_rules_are_preserved(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $available = $this->createScheduledAgent('Available Agent', TeamAvailabilityStatus::Available);
        $busy = $this->createScheduledAgent('Busy Agent', TeamAvailabilityStatus::Busy);
        $offline = $this->createScheduledAgent('Offline Agent', TeamAvailabilityStatus::Offline);
        $approvedLeave = $this->createScheduledAgent('Approved Leave Agent', TeamAvailabilityStatus::Available);

        LeaveRequest::query()->create([
            'user_id' => $approvedLeave->id,
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-06',
            'reason' => 'Approved leave',
            'status' => LeaveRequestStatus::Approved,
        ]);

        $service = app(SmartAssignmentService::class);

        $this->assertTrue($service->isEligible($available));
        $this->assertTrue($service->isEligible($busy));
        $this->assertFalse($service->isEligible($offline));
        $this->assertFalse($service->isEligible($approvedLeave));

        $candidates = $service->eligibleCandidates();
        $candidateIds = collect($candidates)->pluck('id')->all();

        $this->assertContains($available->id, $candidateIds);
        $this->assertContains($busy->id, $candidateIds);
        $this->assertNotContains($offline->id, $candidateIds);
        $this->assertNotContains($approvedLeave->id, $candidateIds);
    }

    public function test_future_approved_leave_does_not_block_today_assignment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent('Future Leave Agent', TeamAvailabilityStatus::Available);

        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => '2026-07-08',
            'end_date' => '2026-07-10',
            'reason' => 'Planned leave',
            'status' => LeaveRequestStatus::Approved,
        ]);

        $this->assertTrue(app(SmartAssignmentService::class)->isEligible($agent));

        $incident = $this->createUnassignedIncident('RD-FUTURE-LEAVE');
        $this->bookAppointment($incident);

        $incident->refresh();
        $this->assertSame($agent->id, $incident->assigned_to_user_id);
    }

    public function test_agent_is_eligible_again_after_approved_leave_ends(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-11 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent('Past Leave Agent', TeamAvailabilityStatus::Available);

        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => '2026-07-08',
            'end_date' => '2026-07-10',
            'reason' => 'Completed leave',
            'status' => LeaveRequestStatus::Approved,
        ]);

        $this->assertTrue(app(SmartAssignmentService::class)->isEligible($agent));
        $this->assertTrue(app(WorkCalendarService::class)->isEligibleForAssignment($agent));
    }

    public function test_offline_status_blocks_assignment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $offlineAgent = $this->createScheduledAgent('Offline Agent', TeamAvailabilityStatus::Offline);
        $availableAgent = $this->createScheduledAgent('Available Agent', TeamAvailabilityStatus::Available);

        $this->assertFalse(app(SmartAssignmentService::class)->isEligible($offlineAgent));

        $incident = $this->createUnassignedIncident('RD-OFFLINE-1');
        $this->bookAppointment($incident);

        $incident->refresh();
        $this->assertSame($availableAgent->id, $incident->assigned_to_user_id);
        $this->assertNotSame($offlineAgent->id, $incident->assigned_to_user_id);
    }

    public function test_manual_on_leave_status_migration_resets_to_offline(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        \Illuminate\Support\Facades\DB::table('users')->where('id', $agent->id)->update([
            'availability_status' => 'on_leave',
            'leave_start_date' => '2026-07-08',
            'leave_end_date' => '2026-07-10',
        ]);

        $migration = require database_path('migrations/2026_07_06_200000_reset_manual_on_leave_availability_status.php');
        $migration->up();

        $row = \Illuminate\Support\Facades\DB::table('users')->where('id', $agent->id)->first();

        $this->assertSame('offline', $row->availability_status);
        $this->assertSame('2026-07-08', $row->leave_start_date);
        $this->assertSame('2026-07-10', $row->leave_end_date);

        $agent->refresh();
        $this->assertSame(TeamAvailabilityStatus::Offline, app(\App\Services\Operations\TeamAvailabilityService::class)->statusFor($agent));
    }

    public function test_unconfigured_work_schedule_shows_warning_on_user_edit(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $agent = User::factory()->create([
            'first_name' => 'Jayram',
            'last_name' => '',
            'name' => 'Jayram',
            'is_active' => true,
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($admin)
            ->get(route('users.edit', $agent))
            ->assertOk()
            ->assertSee('Work schedule is not saved yet', false)
            ->assertSee('Morning Telegram briefings', false);
    }

    public function test_work_calendar_supports_future_presence_fields(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 09:20:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent('Presence Agent', TeamAvailabilityStatus::Available);
        $schedule = $agent->workSchedule;
        $workCalendar = app(WorkCalendarService::class);

        $this->assertSame(490, $workCalendar->expectedWorkingMinutes($schedule));

        $comparison = $workCalendar->compareLoginToSchedule($agent, Carbon::parse('2026-07-06 09:20:00', 'Asia/Kolkata'));
        $this->assertTrue($comparison['is_late']);
        $this->assertSame(20, $comparison['minutes_late']);
        $this->assertSame(490, $comparison['expected_working_minutes']);
    }

    public function test_admin_operations_dashboard_shows_work_calendar_status(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 08:30:00', 'Asia/Kolkata'));

        $admin = User::factory()->create(['name' => 'Ops Admin']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $laterAgent = User::factory()->create(['name' => 'Shipra Later']);
        $laterAgent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $this->createScheduleFor($laterAgent);
        $laterAgent = $laterAgent->fresh(['workSchedule']);

        $workCalendar = app(WorkCalendarService::class)->todayStatusFor($laterAgent);
        $this->assertSame(WorkCalendarDayStatus::StartsLater->value, $workCalendar['status']);
        $this->assertSame('Starts later', $workCalendar['label']);

        $overview = app(TeamAvailabilityOverviewService::class);
        $this->assertSame([], $overview->members());

        $memberSnapshot = $overview->memberSnapshot($laterAgent);
        $this->assertSame('Starts later', $memberSnapshot['work_calendar']['label']);
        $this->assertFalse($memberSnapshot['on_duty']);

        $this->actingAs($admin)
            ->getJson(route('admin.operations.live', ['groups' => 'team']))
            ->assertOk()
            ->assertSee('No team members are currently on duty', false)
            ->assertSee('Team Presence', false);

        Carbon::setTestNow();
    }

    /**
     * @param  list<int>  $weeklyOffDays
     */
    private function createScheduledAgent(
        string $name,
        TeamAvailabilityStatus $status,
        array $weeklyOffDays = [Carbon::SUNDAY],
    ): User {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $user->update([
            'availability_status' => $status,
            'availability_updated_at' => now(),
        ]);

        $this->createScheduleFor($user, $weeklyOffDays);

        $user = $user->fresh(['workSchedule']);

        if ($status !== TeamAvailabilityStatus::Offline) {
            app(PresenceEngineService::class)->startSession($user);
        }

        return $user->fresh(['workSchedule']);
    }

    /**
     * @param  list<int>  $weeklyOffDays
     */
    private function createScheduleFor(User $user, array $weeklyOffDays = [Carbon::SUNDAY]): TeamMemberWorkSchedule
    {
        return TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => $weeklyOffDays,
        ]);
    }

    private function createUnassignedIncident(string $orderId = 'RD-WFC-1'): Incident
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => null,
            'customer_name' => 'Workforce Calendar Customer',
            'customer_email' => 'calendar@example.com',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Workforce calendar case',
            'description' => 'Workforce calendar case.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => null,
        ]);
    }

    private function bookAppointment(Incident $incident): void
    {
        app(SupportAppointmentService::class)->book($incident, [
            'preferred_date' => '2026-07-07',
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
            'additional_notes' => 'Need remote support.',
        ]);
    }
}
