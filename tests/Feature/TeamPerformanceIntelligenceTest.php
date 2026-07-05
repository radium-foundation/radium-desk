<?php

namespace Tests\Feature;

use App\Enums\CompanyHolidayType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\LeaveRequestStatus;
use App\Enums\PerformancePeriod;
use App\Enums\TeamAvailabilityStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\CompanyHoliday;
use App\Models\Incident;
use App\Models\LeaveRequest;
use App\Models\Order;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\IncidentReferenceService;
use App\Services\Operations\IraPerformanceInsightsService;
use App\Services\Operations\PerformancePeriodService;
use App\Services\Operations\PresenceEngineService;
use App\Services\Operations\TeamMemberActivityService;
use App\Services\Operations\TeamPerformanceMetricsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TeamPerformanceIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'presence.active_threshold_minutes' => 5,
            'presence.away_timeout_minutes' => 15,
            'performance.high_appointment_load' => 5,
            'performance.high_communication_weekly' => 10,
            'performance.scheduled_call_capacity_ratio' => 2,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_attendance_excludes_holidays(): void
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

        $range = app(PerformancePeriodService::class)->resolve(PerformancePeriod::ThisWeek);
        $metrics = app(TeamPerformanceMetricsService::class)->metricsFor($agent, PerformancePeriod::ThisWeek);

        $this->assertSame(5, $metrics->attendance['working_days']);
        $this->assertSame(1, $metrics->attendance['present_days']);
    }

    public function test_approved_leave_counted_correctly(): void
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

    public function test_lunch_excluded_from_idle(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 13:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Lunch Agent');
        $presenceEngine = app(PresenceEngineService::class);
        $session = $presenceEngine->startSession($agent);

        Carbon::setTestNow(Carbon::parse('2026-07-06 13:45:00', 'Asia/Kolkata'));
        $presenceEngine->tickSession($session->fresh(), now(), hasActivity: false);
        $session->refresh();

        $this->assertGreaterThan(0, $session->lunch_duration_seconds);
        $this->assertSame(0, $session->extra_idle_duration_seconds);
    }

    public function test_active_hours_calculated_correctly(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Active Agent');
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

        $metrics = app(TeamPerformanceMetricsService::class)->metricsFor($agent, PerformancePeriod::Today);

        $this->assertSame(7200, $metrics->presence['active_desk_seconds']);
        $this->assertSame('2h 0m', $metrics->presence['active_desk_label']);
    }

    public function test_overtime_calculated_correctly(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 18:10:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Overtime Agent');
        $presenceEngine = app(PresenceEngineService::class);
        $presenceEngine->startSession($agent, Carbon::parse('2026-07-06 09:00:00'));
        $presenceEngine->closeSession($agent, WorkSessionEndReason::ManualLogout);

        $session = WorkSession::query()->where('user_id', $agent->id)->first();

        $this->assertSame(600, $session?->overtime_seconds);

        $metrics = app(TeamPerformanceMetricsService::class)->metricsFor($agent, PerformancePeriod::Today);
        $this->assertSame(600, $metrics->presence['overtime_seconds']);
    }

    public function test_case_completion_counted(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 12:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Closer Agent');
        $incident = $this->createIncidentFor($agent);
        $incident->update([
            'status' => IncidentStatus::Closed,
            'updated_by' => $agent->id,
            'updated_at' => now(),
        ]);

        $metrics = app(TeamPerformanceMetricsService::class)->metricsFor($agent, PerformancePeriod::Today);

        $this->assertSame(1, $metrics->customerWork['cases_completed']);
    }

    public function test_communication_count_counted(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Communication Agent');
        app(PresenceEngineService::class)->startSession($agent);

        app(TeamMemberActivityService::class)->recordCustomerCommunication($agent);
        app(TeamMemberActivityService::class)->recordCustomerCommunication($agent);
        app(TeamMemberActivityService::class)->recordCustomerCommunication($agent);

        $metrics = app(TeamPerformanceMetricsService::class)->metricsFor($agent, PerformancePeriod::Today);

        $this->assertSame(3, $metrics->customerWork['customer_communications']);
    }

    public function test_sla_percentage_calculated(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 12:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('SLA Agent');

        $withinSla = $this->createIncidentFor($agent, 'RD-SLA-1');
        $withinSla->update([
            'status' => IncidentStatus::Closed,
            'updated_by' => $agent->id,
            'updated_at' => now(),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-08 12:00:00', 'Asia/Kolkata'));

        $overdue = $this->createIncidentFor($agent, 'RD-SLA-2');
        $overdue->forceFill([
            'created_at' => Carbon::parse('2026-07-01 12:00:00'),
            'status' => IncidentStatus::Closed,
            'updated_by' => $agent->id,
            'updated_at' => now(),
        ])->save();

        $metrics = app(TeamPerformanceMetricsService::class)->metricsFor($agent, PerformancePeriod::ThisMonth);

        $this->assertSame(50.0, $metrics->quality['sla_success_percentage']);
    }

    public function test_team_member_sees_only_own_stats(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Own Stats Agent');
        $other = $this->createAgentWithSchedule('Other Agent');

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-06',
            'login_at' => Carbon::parse('2026-07-06 09:00:00'),
            'communication_events_count' => 7,
            'on_time_login' => true,
        ]);
        WorkSession::query()->create([
            'user_id' => $other->id,
            'work_date' => '2026-07-06',
            'login_at' => Carbon::parse('2026-07-06 09:00:00'),
            'communication_events_count' => 99,
            'on_time_login' => true,
        ]);

        $this->actingAs($agent)
            ->get(route('my-performance.index'))
            ->assertOk()
            ->assertSee('7')
            ->assertDontSee('99');
    }

    public function test_admin_sees_team_stats(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $admin = User::factory()->create(['name' => 'Ops Admin']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $agent = $this->createAgentWithSchedule('Visible Agent');
        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-06',
            'login_at' => Carbon::parse('2026-07-06 09:00:00'),
            'cases_handled_count' => 12,
            'on_time_login' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.workforce.performance.index'))
            ->assertOk()
            ->assertSee('Team Performance')
            ->assertSee('Visible Agent')
            ->assertSee('12');
    }

    public function test_ira_generates_contextual_insights(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Insight Agent');

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-06',
            'login_at' => Carbon::parse('2026-07-06 09:00:00'),
            'communication_events_count' => 12,
            'on_time_login' => true,
        ]);

        for ($index = 0; $index < 6; $index++) {
            $incident = $this->createIncidentFor($agent, 'RD-APT-'.$index);
            \App\Models\SupportAppointment::query()->create([
                'incident_id' => $incident->id,
                'preferred_date' => '2026-07-06',
                'preferred_time_slot' => \App\Enums\SupportAppointmentTimeSlot::Morning,
                'phone_number' => '9876543210',
            ]);
        }

        $dailyInsights = app(IraPerformanceInsightsService::class)->insights(PerformancePeriod::Today);
        $weeklyInsights = app(IraPerformanceInsightsService::class)->insights(PerformancePeriod::ThisWeek);
        $dailyMessages = collect($dailyInsights)->map(fn ($insight) => $insight->message)->all();
        $weeklyMessages = collect($weeklyInsights)->map(fn ($insight) => $insight->message)->all();

        $this->assertTrue(
            collect($dailyMessages)->contains(fn (string $message): bool => str_contains($message, 'Support load is high today')),
        );
        $this->assertTrue(
            collect($weeklyMessages)->contains(fn (string $message): bool => str_contains($message, 'Insight Agent handled 12 customer follow-ups')),
        );
        $this->assertFalse(
            collect([...$dailyMessages, ...$weeklyMessages])->contains(fn (string $message): bool => str_contains(strtolower($message), 'idle')),
        );
    }

    private function createAgentWithSchedule(string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $user->update([
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);

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

    private function createIncidentFor(User $agent, string $orderId = 'RD-PERF-1'): Incident
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => null,
            'customer_name' => 'Performance Customer',
            'customer_email' => 'perf@example.com',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Performance case',
            'description' => 'Performance case.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $agent->id,
        ]);
    }
}
