<?php

namespace Tests\Feature;

use App\Enums\CompanyHolidayType;
use App\Enums\LeaveRequestStatus;
use App\Enums\PerformancePeriod;
use App\Enums\WorkSessionEndReason;
use App\Models\CompanyHoliday;
use App\Models\LeaveRequest;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use App\Models\WorkSession;
use App\Services\Operations\IraOwnerIntelligenceService;
use App\Services\Operations\TeamPerformanceMetricsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttendanceRegisterConsumerMigrationTest extends TestCase
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

    public function test_team_performance_metrics_hydrate_register_when_rows_are_missing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Hydration Agent');

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-06',
            'login_at' => Carbon::parse('2026-07-06 09:00:00'),
            'logout_at' => Carbon::parse('2026-07-06 18:00:00'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'active_duration_seconds' => 7200,
            'expected_working_minutes' => 490,
            'on_time_login' => true,
        ]);

        $this->assertSame(0, WorkforceAttendanceDay::query()->count());

        $metrics = app(TeamPerformanceMetricsService::class)->metricsFor($agent, PerformancePeriod::Today);

        $this->assertSame(1, WorkforceAttendanceDay::query()->where('user_id', $agent->id)->count());
        $this->assertSame(1, $metrics->attendance['present_days']);
        $this->assertSame(7200, $metrics->presence['active_desk_seconds']);
        $this->assertSame(100.0, $metrics->login['on_time_percentage']);
    }

    public function test_team_performance_attendance_excludes_holidays_via_register(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        CompanyHoliday::query()->create([
            'holiday_date' => '2026-07-08',
            'name' => 'Company Event',
            'type' => CompanyHolidayType::National,
        ]);

        $agent = $this->createAgentWithSchedule('Holiday Agent');
        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-06',
            'login_at' => Carbon::parse('2026-07-06 09:00:00'),
            'logout_at' => Carbon::parse('2026-07-06 18:00:00'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'on_time_login' => true,
            'expected_working_minutes' => 490,
        ]);
        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-08',
            'login_at' => Carbon::parse('2026-07-08 09:00:00'),
            'on_time_login' => true,
            'expected_working_minutes' => 490,
        ]);

        $metrics = app(TeamPerformanceMetricsService::class)->metricsFor($agent, PerformancePeriod::ThisWeek);

        $this->assertSame(5, $metrics->attendance['working_days']);
        $this->assertSame(1, $metrics->attendance['present_days']);
    }

    public function test_team_performance_leave_days_match_register(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Leave Agent');

        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => '2026-07-07',
            'end_date' => '2026-07-08',
            'reason' => 'Planned leave',
            'status' => LeaveRequestStatus::Approved,
        ]);

        $metrics = app(TeamPerformanceMetricsService::class)->metricsFor($agent, PerformancePeriod::ThisWeek);

        $this->assertSame(2, $metrics->attendance['leave_days']);
    }

    public function test_ira_evening_attendance_reads_from_register(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 20:00:00', 'Asia/Kolkata'));

        $onTimeAgent = $this->createAgentWithSchedule('On Time Agent');
        $lateAgent = $this->createAgentWithSchedule('Late Agent');

        WorkSession::query()->create([
            'user_id' => $onTimeAgent->id,
            'work_date' => '2026-07-10',
            'login_at' => Carbon::parse('2026-07-10 09:00:00'),
            'logout_at' => Carbon::parse('2026-07-10 18:00:00'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'on_time_login' => true,
            'expected_working_minutes' => 490,
        ]);
        WorkSession::query()->create([
            'user_id' => $lateAgent->id,
            'work_date' => '2026-07-10',
            'login_at' => Carbon::parse('2026-07-10 09:25:00'),
            'logout_at' => Carbon::parse('2026-07-10 18:00:00'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'on_time_login' => false,
            'expected_working_minutes' => 490,
        ]);

        $report = app(IraOwnerIntelligenceService::class)->buildEveningReport();

        $this->assertGreaterThanOrEqual(1, WorkforceAttendanceDay::query()->count());
        $this->assertSame(1, $report->attendance['on_time_logins']);
        $this->assertSame(1, $report->attendance['late_logins']);
        $this->assertSame(2, $report->attendance['manual_logouts']);
        $this->assertContains('Late Agent', $report->team['late_arrivals']);
    }

    public function test_existing_register_rows_are_reused_without_duplicates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Cached Agent');

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-06',
            'login_at' => Carbon::parse('2026-07-06 09:00:00'),
            'logout_at' => Carbon::parse('2026-07-06 18:00:00'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'on_time_login' => true,
            'expected_working_minutes' => 490,
        ]);

        app(TeamPerformanceMetricsService::class)->metricsFor($agent, PerformancePeriod::Today);
        $firstCount = WorkforceAttendanceDay::query()->where('user_id', $agent->id)->count();

        app(TeamPerformanceMetricsService::class)->metricsFor($agent, PerformancePeriod::Today);
        $secondCount = WorkforceAttendanceDay::query()->where('user_id', $agent->id)->count();

        $this->assertSame(1, $firstCount);
        $this->assertSame(1, $secondCount);
    }

    private function createAgentWithSchedule(string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
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

        return $user->fresh(['workSchedule']);
    }
}
