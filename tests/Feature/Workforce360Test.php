<?php

namespace Tests\Feature;

use App\Enums\AttendanceDayStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\LeaveRequestStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\Incident;
use App\Models\LeaveRequest;
use App\Models\Order;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use App\Models\WorkSession;
use App\Services\IncidentReferenceService;
use App\Services\Operations\PresenceEngineService;
use App\Services\Operations\Workforce360Service;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Workforce360Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'presence.active_threshold_minutes' => 5,
            'presence.away_timeout_minutes' => 15,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_can_view_team_workforce(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->createScheduledAgent('Tracked Agent');

        $this->actingAs($admin)
            ->get(route('workforce.index'))
            ->assertOk()
            ->assertSee('Team Workforce')
            ->assertSee('Coming in Sprint 3')
            ->assertSee('Workforce Today')
            ->assertSee('Attention Required')
            ->assertSee('Available')
            ->assertSee('Pending Leave');
    }

    public function test_team_workforce_summary_separates_state_from_attention(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $available = $this->createScheduledAgent('Available Agent');
        $busy = $this->createScheduledAgent('Busy Agent', TeamAvailabilityStatus::Busy);
        $this->createScheduledAgent('Offline Agent');
        app(PresenceEngineService::class)->startSession($available->fresh(['workSchedule', 'roles']));
        app(PresenceEngineService::class)->startSession($busy->fresh(['workSchedule', 'roles']));

        LeaveRequest::query()->create([
            'user_id' => $available->id,
            'start_date' => '2026-07-12',
            'end_date' => '2026-07-12',
            'reason' => 'Personal',
            'status' => LeaveRequestStatus::Pending,
        ]);

        $team = app(Workforce360Service::class)->team($admin);
        $workforceByKey = collect($team->capacity['workforce_today'])->keyBy('key');
        $attentionByKey = collect($team->capacity['attention_required'])->keyBy('key');

        $this->assertSame(1, $workforceByKey['available']['value']);
        $this->assertSame(1, $workforceByKey['busy']['value']);
        $this->assertGreaterThanOrEqual(1, $workforceByKey['offline']['value']);
        $this->assertSame(1, $attentionByKey['pending_leave']['value']);
        $this->assertArrayHasKey('workforce_today', $team->capacity);
        $this->assertArrayHasKey('attention_required', $team->capacity);
        $this->assertStringContainsString('Available 1', $team->hero['summary']);
        $this->assertStringContainsString('Busy 1', $team->hero['summary']);
    }

    public function test_team_table_splits_shift_and_active_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $agent = $this->createScheduledAgent('Shift Agent');
        $session = app(PresenceEngineService::class)->startSession($agent->fresh(['workSchedule', 'roles']));
        $session?->update(['active_duration_seconds' => 2460]);

        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $this->actingAs($admin)
            ->get(route('workforce.index'))
            ->assertOk()
            ->assertSee('Shift', false)
            ->assertSee('Active Today', false)
            ->assertDontSee('data-label="Attention"', false)
            ->assertSee('09:00 - 18:00', false)
            ->assertSee('41m', false);
    }

    public function test_status_reason_shows_session_timed_out(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $agent = $this->createScheduledAgent('Timeout Agent');

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-10',
            'login_at' => Carbon::parse('2026-07-10 09:05:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse('2026-07-10 09:40:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::AwayTimeout,
            'on_time_login' => true,
            'active_duration_seconds' => 2100,
        ]);

        $team = app(Workforce360Service::class)->team($admin);
        $row = collect($team->members)->firstWhere('id', $agent->id);

        $this->assertNotNull($row);
        $this->assertSame('Offline', $row['availability']['label']);
        $this->assertSame('Session Timed Out', $row['status_reason']);
        $this->assertTrue($row['has_session_timeout'] ?? false);
        $attentionByKey = collect($team->capacity['attention_required'])->keyBy('key');
        $this->assertSame(1, $attentionByKey['session_timeout']['value']);
    }

    public function test_status_reason_shows_shift_not_started(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 08:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $agent = $this->createScheduledAgent('Early Agent');

        $team = app(Workforce360Service::class)->team($admin);
        $row = collect($team->members)->firstWhere('id', $agent->id);
        $calendar = app(\App\Services\Operations\WorkCalendarService::class)->todayStatusFor($agent);

        $this->assertSame('starts_later', $calendar['status']);

        if ($row !== null) {
            $this->assertSame('Shift Not Started', $row['status_reason']);
        } else {
            $workforceByKey = collect($team->capacity['workforce_today'])->keyBy('key');
            $this->assertGreaterThanOrEqual(1, $workforceByKey['offline']['value']);
        }
    }

    public function test_late_login_counts_without_treating_early_login_as_late(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $early = $this->createScheduledAgent('Early Login Agent');
        $late = $this->createScheduledAgent('Late Login Agent');

        Carbon::setTestNow(Carbon::parse('2026-07-10 08:30:00', 'Asia/Kolkata'));
        app(PresenceEngineService::class)->startSession($early->fresh(['workSchedule', 'roles']));

        Carbon::setTestNow(Carbon::parse('2026-07-10 09:25:00', 'Asia/Kolkata'));
        app(PresenceEngineService::class)->startSession($late->fresh(['workSchedule', 'roles']));

        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $team = app(Workforce360Service::class)->team($admin);
        $earlyRow = collect($team->members)->firstWhere('id', $early->id);
        $lateRow = collect($team->members)->firstWhere('id', $late->id);
        $attentionByKey = collect($team->capacity['attention_required'])->keyBy('key');

        $this->assertSame(1, $attentionByKey['late_login']['value']);
        $this->assertFalse($earlyRow['is_late_login'] ?? true);
        $this->assertTrue($lateRow['is_late_login'] ?? false);
    }

    public function test_current_case_resolved_via_batched_incident_lookup(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $withCase = $this->createScheduledAgent('Case Agent');
        $withoutCase = $this->createScheduledAgent('No Case Agent');

        $session = app(PresenceEngineService::class)->startSession($withCase->fresh(['workSchedule', 'roles']));
        app(PresenceEngineService::class)->startSession($withoutCase->fresh(['workSchedule', 'roles']));

        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $order = Order::query()->create([
            'order_id' => 'RD-WF-CASE',
            'serial_number' => 'SN-WF-CASE',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $withCase->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'Refund',
            'source' => IncidentSource::Call,
            'title' => 'Refund case',
            'description' => 'Refund case',
            'status' => IncidentStatus::InProgress,
            'created_by' => $withCase->id,
            'updated_by' => $withCase->id,
            'assigned_to_user_id' => $withCase->id,
        ]);

        $session?->update(['current_incident_id' => $incident->id]);

        $team = app(Workforce360Service::class)->team($admin);
        $withCaseRow = collect($team->members)->firstWhere('id', $withCase->id);
        $withoutCaseRow = collect($team->members)->firstWhere('id', $withoutCase->id);

        $this->assertSame($incident->display_reference, $withCaseRow['current_case']['reference']);
        $this->assertSame('Refund', $withCaseRow['current_case']['category']);
        $this->assertSame('In Progress', $withCaseRow['current_case']['status_label']);
        $this->assertNull($withoutCaseRow['current_case']);

        $this->actingAs($admin)
            ->get(route('workforce.index'))
            ->assertOk()
            ->assertSee($incident->display_reference, false)
            ->assertSee('Refund • In Progress', false)
            ->assertSee('Cases', false);
    }

    public function test_workload_shows_cases_label_with_tone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $agent = $this->createScheduledAgent('Workload Agent');
        app(PresenceEngineService::class)->startSession($agent->fresh(['workSchedule', 'roles']));

        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        for ($i = 1; $i <= 6; $i++) {
            $order = Order::query()->create([
                'order_id' => 'RD-WF-WL-'.$i,
                'serial_number' => 'SN-WF-WL-'.$i,
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'status' => 'active',
                'created_by' => $agent->id,
            ]);

            Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => 'SC'.str_pad((string) (15800 + $i), 5, '0', STR_PAD_LEFT),
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => 'Workload case '.$i,
                'description' => 'Workload case '.$i,
                'status' => IncidentStatus::Open,
                'created_by' => $agent->id,
                'updated_by' => $agent->id,
                'assigned_to_user_id' => $agent->id,
            ]);
        }

        $team = app(Workforce360Service::class)->team($admin);
        $row = collect($team->members)->firstWhere('id', $agent->id);

        $this->assertSame(6, $row['open_work_count']);

        $this->actingAs($admin)
            ->get(route('workforce.index'))
            ->assertOk()
            ->assertSee('6 Cases', false)
            ->assertSee('workforce360-workload__value--danger', false);
    }

    public function test_on_leave_count_and_attendance_exception_from_existing_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $onLeave = $this->createScheduledAgent('Leave Agent');
        $away = $this->createScheduledAgent('Away Agent');

        LeaveRequest::query()->create([
            'user_id' => $onLeave->id,
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-10',
            'reason' => 'Approved leave',
            'status' => LeaveRequestStatus::Approved,
        ]);

        WorkforceAttendanceDay::query()->create([
            'user_id' => $away->id,
            'work_date' => '2026-07-10',
            'status' => AttendanceDayStatus::Away,
            'calendar_status' => \App\Enums\WorkCalendarDayStatus::Working,
            'is_working_day' => true,
            'is_company_holiday' => false,
            'is_on_leave' => false,
            'has_schedule' => true,
            'on_time_login' => true,
            'session_count' => 1,
            'active_duration_seconds' => 1000,
            'away_timeout_count' => 1,
            'computed_at' => now(),
            'source_version' => 1,
        ]);

        $team = app(Workforce360Service::class)->team($admin);
        $workforceByKey = collect($team->capacity['workforce_today'])->keyBy('key');
        $attentionByKey = collect($team->capacity['attention_required'])->keyBy('key');

        $this->assertSame(1, $workforceByKey['on_leave']['value']);
        $this->assertSame(1, $attentionByKey['attendance_exception']['value']);
    }

    public function test_agent_can_view_my_workforce(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent('Self Agent');

        $this->actingAs($agent)
            ->get(route('my-workforce.index'))
            ->assertOk()
            ->assertSee('My Workforce')
            ->assertSee('Self Agent')
            ->assertSee('Today schedule');
    }

    public function test_agent_can_view_team_workforce_read_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent('Team Viewer');

        $this->actingAs($agent)
            ->get(route('workforce.index'))
            ->assertOk()
            ->assertSee('Team Workforce');
    }

    public function test_agent_cannot_view_other_member_workforce(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent('Agent One');
        $other = $this->createScheduledAgent('Agent Two');

        $this->actingAs($agent)
            ->get(route('workforce.show', $other))
            ->assertForbidden();
    }

    public function test_admin_can_view_individual_workforce(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $agent = $this->createScheduledAgent('Visible Agent');

        $this->actingAs($admin)
            ->get(route('workforce.show', $agent))
            ->assertOk()
            ->assertSee('Visible Agent')
            ->assertSee('Individual Workforce')
            ->assertSee('Presence');
    }

    public function test_show_redirects_to_my_workforce_for_self(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent('Redirect Agent');

        $this->actingAs($agent)
            ->get(route('workforce.show', $agent))
            ->assertRedirect(route('my-workforce.index'));
    }

    public function test_timeline_tab_shows_placeholder(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent('Timeline Agent');

        $this->actingAs($agent)
            ->get(route('my-workforce.index', ['tab' => 'timeline']))
            ->assertOk()
            ->assertSee('Timeline Engine');
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }

    private function createScheduledAgent(
        string $name,
        TeamAvailabilityStatus $availability = TeamAvailabilityStatus::Available,
    ): User {
        $user = User::factory()->create([
            'name' => $name,
            'is_active' => true,
            'availability_status' => $availability,
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

        return $user->fresh(['workSchedule', 'roles']);
    }
}
