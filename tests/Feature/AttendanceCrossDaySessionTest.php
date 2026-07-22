<?php

namespace Tests\Feature;

use App\Enums\AttendanceDayStatus;
use App\Enums\PerformancePeriod;
use App\Enums\WorkSessionEndReason;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use App\Models\WorkSession;
use App\Services\Operations\AttendanceRegisterService;
use App\Services\Operations\PresenceEngineService;
use App\Services\Operations\TeamPerformanceMetricsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttendanceCrossDaySessionTest extends TestCase
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

    public function test_unfinalized_register_rows_are_recomputed_on_resolve(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 11:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $presence = app(PresenceEngineService::class);
        $presence->startSession($agent);

        $staleRow = WorkforceAttendanceDay::query()->where('user_id', $agent->id)->first();
        $this->assertNotNull($staleRow);
        $this->assertSame(AttendanceDayStatus::Active, $staleRow->status);
        $this->assertNull($staleRow->finalized_at);

        $staleRow->update([
            'active_duration_seconds' => 0,
            'status' => AttendanceDayStatus::NotStarted->value,
            'computed_at' => now()->subHour(),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-07 11:30:00', 'Asia/Kolkata'));
        $presence->recordActivity($agent);

        $resolved = app(AttendanceRegisterService::class)->resolveDay(
            user: $agent,
            workDate: Carbon::parse('2026-07-07'),
            referenceAt: now(),
        );

        $this->assertNotNull($resolved);
        $this->assertSame(AttendanceDayStatus::Active, $resolved->status);
        $this->assertGreaterThan(0, $resolved->active_duration_seconds);
    }

    public function test_carry_over_open_session_marks_today_active_without_inflating_metrics(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-06',
            'login_at' => Carbon::parse('2026-07-06 09:00:00', 'Asia/Kolkata'),
            'last_activity_at' => Carbon::parse('2026-07-07 09:58:00', 'Asia/Kolkata'),
            'last_tick_at' => Carbon::parse('2026-07-07 09:58:00', 'Asia/Kolkata'),
            'active_duration_seconds' => 120_000,
            'on_time_login' => true,
        ]);

        $row = app(AttendanceRegisterService::class)->refreshDay(
            user: $agent,
            workDate: Carbon::parse('2026-07-07'),
            referenceAt: now(),
            allowPreShiftSkip: false,
        );

        $this->assertNotNull($row);
        $this->assertSame(AttendanceDayStatus::Active, $row->status);
        $this->assertSame(0, $row->session_count);
        $this->assertSame(0, $row->active_duration_seconds);
        $this->assertSame(0, $row->overtime_seconds);
    }

    public function test_multi_day_logout_overtime_is_capped_to_login_day_shift_end(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 09:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $presence = app(PresenceEngineService::class);
        $presence->startSession($agent);

        Carbon::setTestNow(Carbon::parse('2026-07-08 03:27:00', 'Asia/Kolkata'));
        $session = $presence->closeSession($agent, WorkSessionEndReason::ManualLogout);

        $this->assertNotNull($session);
        $this->assertSame(21_599, $session->overtime_seconds);
        $this->assertLessThan(33 * 3600, $session->overtime_seconds);
    }

    public function test_activity_on_new_day_rolls_session_forward(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 17:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $presence = app(PresenceEngineService::class);
        $presence->startSession($agent);

        Carbon::setTestNow(Carbon::parse('2026-07-07 09:05:00', 'Asia/Kolkata'));
        $presence->recordActivity($agent);

        $sessions = WorkSession::query()
            ->where('user_id', $agent->id)
            ->orderBy('login_at')
            ->get();

        $this->assertCount(2, $sessions);
        $this->assertNotNull($sessions->first()?->logout_at);
        $this->assertSame(
            WorkSessionEndReason::SessionReplaced,
            $sessions->first()?->ended_reason,
        );
        $this->assertTrue($sessions->last()?->isOpen());
        $this->assertSame('2026-07-07', $sessions->last()?->work_date->toDateString());
    }

    public function test_closing_session_refreshes_login_day_register(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 09:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $presence = app(PresenceEngineService::class);
        $presence->startSession($agent);

        Carbon::setTestNow(Carbon::parse('2026-07-07 18:10:00', 'Asia/Kolkata'));
        $presence->closeSession($agent, WorkSessionEndReason::ManualLogout);

        $loginDay = WorkforceAttendanceDay::query()
            ->where('user_id', $agent->id)
            ->whereDate('work_date', '2026-07-06')
            ->first();

        $this->assertNotNull($loginDay);
        $this->assertSame(1, $loginDay->session_count);
        $this->assertNotNull($loginDay->finalized_at);
        $this->assertGreaterThan(0, $loginDay->overtime_seconds);
    }

    public function test_team_metrics_recompute_unfinalized_register_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 11:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $presence = app(PresenceEngineService::class);
        $presence->startSession($agent);

        WorkforceAttendanceDay::query()
            ->where('user_id', $agent->id)
            ->update([
                'active_duration_seconds' => 0,
                'overtime_seconds' => 120_420,
                'status' => AttendanceDayStatus::NotStarted->value,
                'finalized_at' => null,
            ]);

        $metrics = app(TeamPerformanceMetricsService::class)->metricsFor($agent, PerformancePeriod::Today);

        $this->assertGreaterThan(0, $metrics->presence['active_desk_seconds']);
        $this->assertSame(0, $metrics->presence['overtime_seconds']);
    }

    private function createScheduledAgent(): User
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $agent->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        return $agent->fresh(['workSchedule']);
    }
}
