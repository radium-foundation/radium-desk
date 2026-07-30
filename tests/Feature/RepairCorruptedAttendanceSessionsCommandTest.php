<?php

namespace Tests\Feature;

use App\Enums\WorkSessionEndReason;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use App\Models\WorkSession;
use App\Services\Operations\PresenceEngineService;
use App\Services\Workforce\MonthlyAttendanceMatrixService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RepairCorruptedAttendanceSessionsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Carbon::setTestNow(Carbon::parse('2026-07-28 20:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_repairs_corrupted_closed_sessions_and_reconciles_attendance(): void
    {
        $agent = $this->createScheduledAgent();

        $corrupted = WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-28',
            'login_at' => Carbon::parse('2026-07-28 09:56:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse('2026-07-28 18:26:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::AwayTimeout,
            'session_duration_seconds' => 30_600,
            'active_duration_seconds' => 49_715,
            'idle_duration_seconds' => 893,
            'break_duration_seconds' => 893,
            'extra_idle_duration_seconds' => 0,
            'lunch_duration_seconds' => 0,
            // Correct OT for 18:26 logout vs 18:00 shift end (avoids double-counting this fixture).
            'overtime_seconds' => 26 * 60,
            'last_activity_at' => Carbon::parse('2026-07-28 18:10:00', 'Asia/Kolkata'),
            'last_tick_at' => Carbon::parse('2026-07-28 23:59:59', 'Asia/Kolkata'),
            'on_time_login' => true,
        ]);

        WorkforceAttendanceDay::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-28',
            'status' => 'completed',
            'calendar_status' => 'working',
            'is_working_day' => true,
            'is_company_holiday' => false,
            'is_on_leave' => false,
            'has_schedule' => true,
            'session_count' => 1,
            'session_duration_seconds' => 30_600,
            'active_duration_seconds' => 49_715,
            'overtime_seconds' => 26 * 60,
            'computed_at' => now()->subHour(),
            'source_version' => 1,
        ]);

        $this->artisan('attendance:repair-corrupted-sessions')
            ->expectsOutputToContain('Active duration sessions repaired: 1')
            ->expectsOutputToContain('Sessions repaired: 1')
            ->expectsOutputToContain('Date range reconciled: 2026-07-28 → 2026-07-28')
            ->assertSuccessful();

        $corrupted->refresh();

        $this->assertSame(30_600, (int) $corrupted->active_duration_seconds);
        $this->assertSame(30_600, (int) $corrupted->session_duration_seconds);
        $this->assertSame(893, (int) $corrupted->idle_duration_seconds);
        $this->assertSame(893, (int) $corrupted->break_duration_seconds);
        $this->assertSame(
            '2026-07-28 23:59:59',
            $corrupted->last_tick_at?->timezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-07-28 09:56:00',
            $corrupted->login_at?->timezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-07-28 18:26:00',
            $corrupted->logout_at?->timezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
        );

        $day = WorkforceAttendanceDay::query()
            ->where('user_id', $agent->id)
            ->whereDate('work_date', '2026-07-28')
            ->first();

        $this->assertNotNull($day);
        $this->assertSame(30_600, (int) $day->active_duration_seconds);
    }

    public function test_repairs_historical_overtime_and_rebuilds_day_and_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();

        $corruptedOt = 6_707_700; // 1863h 15m class inflation
        $loginAt = Carbon::parse('2026-07-06 09:00:00', 'Asia/Kolkata');
        $logoutAt = Carbon::parse('2026-07-08 03:27:00', 'Asia/Kolkata');

        $session = WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-06',
            'login_at' => $loginAt,
            'logout_at' => $logoutAt,
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'session_duration_seconds' => (int) $loginAt->diffInSeconds($logoutAt),
            'active_duration_seconds' => 8 * 3600,
            'idle_duration_seconds' => 0,
            'overtime_seconds' => $corruptedOt,
            'last_activity_at' => $logoutAt->copy(),
            'last_tick_at' => $logoutAt->copy(),
            'on_time_login' => true,
            'expected_working_minutes' => 490,
        ]);

        $expectedOt = app(PresenceEngineService::class)->recalculateOvertimeSeconds($session);
        $this->assertSame(21_599, $expectedOt);
        $this->assertNotSame($corruptedOt, $expectedOt);

        WorkforceAttendanceDay::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-06',
            'status' => 'completed',
            'calendar_status' => 'working',
            'is_working_day' => true,
            'is_company_holiday' => false,
            'is_on_leave' => false,
            'has_schedule' => true,
            'session_count' => 1,
            'session_duration_seconds' => (int) $session->session_duration_seconds,
            'active_duration_seconds' => (int) $session->active_duration_seconds,
            'overtime_seconds' => $corruptedOt,
            'computed_at' => now()->subHour(),
            'source_version' => 1,
        ]);

        $reportBefore = app(MonthlyAttendanceMatrixService::class)->build(Carbon::parse('2026-07-01'));
        $memberBefore = collect($reportBefore->members)->firstWhere('userId', $agent->id);
        $this->assertSame('1863h 15m', $memberBefore?->summary->overtimeLabel);

        $this->artisan('attendance:repair-corrupted-sessions')
            ->expectsOutputToContain('Overtime sessions repaired: 1')
            ->expectsOutputToContain('Sessions repaired: 1')
            ->expectsOutputToContain('Date range reconciled: 2026-07-06 → 2026-07-06')
            ->assertSuccessful();

        $session->refresh();
        $this->assertSame($expectedOt, (int) $session->overtime_seconds);
        $this->assertLessThan(33 * 3600, (int) $session->overtime_seconds);

        $day = WorkforceAttendanceDay::query()
            ->where('user_id', $agent->id)
            ->whereDate('work_date', '2026-07-06')
            ->first();

        $this->assertNotNull($day);
        $this->assertSame($expectedOt, (int) $day->overtime_seconds);

        $reportAfter = app(MonthlyAttendanceMatrixService::class)->build(Carbon::parse('2026-07-01'));
        $memberAfter = collect($reportAfter->members)->firstWhere('userId', $agent->id);

        $this->assertNotNull($memberAfter);
        $this->assertSame(
            app(PresenceEngineService::class)->formatDuration($expectedOt),
            $memberAfter->summary->overtimeLabel,
        );
        $this->assertNotSame('1863h 15m', $memberAfter->summary->overtimeLabel);
    }

    public function test_leaves_healthy_and_open_sessions_untouched(): void
    {
        $agent = $this->createScheduledAgent();

        $healthy = WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-28',
            'login_at' => Carbon::parse('2026-07-28 09:00:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse('2026-07-28 18:00:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'session_duration_seconds' => 32_400,
            'active_duration_seconds' => 28_800,
            'idle_duration_seconds' => 3_600,
            'overtime_seconds' => 0,
            'last_activity_at' => Carbon::parse('2026-07-28 17:50:00', 'Asia/Kolkata'),
            'last_tick_at' => Carbon::parse('2026-07-28 18:00:00', 'Asia/Kolkata'),
            'on_time_login' => true,
        ]);

        $open = WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-27',
            'login_at' => Carbon::parse('2026-07-27 09:00:00', 'Asia/Kolkata'),
            'logout_at' => null,
            'session_duration_seconds' => 0,
            'active_duration_seconds' => 50_000,
            'overtime_seconds' => 0,
            'last_activity_at' => Carbon::parse('2026-07-27 10:00:00', 'Asia/Kolkata'),
            'last_tick_at' => Carbon::parse('2026-07-27 23:59:59', 'Asia/Kolkata'),
            'on_time_login' => true,
        ]);

        $equal = WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-26',
            'login_at' => Carbon::parse('2026-07-26 09:00:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse('2026-07-26 10:00:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::AwayTimeout,
            'session_duration_seconds' => 3_600,
            'active_duration_seconds' => 3_600,
            'overtime_seconds' => 0,
            'last_activity_at' => Carbon::parse('2026-07-26 09:55:00', 'Asia/Kolkata'),
            'last_tick_at' => Carbon::parse('2026-07-26 10:00:00', 'Asia/Kolkata'),
            'on_time_login' => true,
        ]);

        $this->artisan('attendance:repair-corrupted-sessions')
            ->expectsOutputToContain('Active duration sessions repaired: 0')
            ->expectsOutputToContain('Overtime sessions repaired: 0')
            ->expectsOutputToContain('Sessions repaired: 0')
            ->expectsOutputToContain('Date range reconciled: none')
            ->assertSuccessful();

        $healthy->refresh();
        $open->refresh();
        $equal->refresh();

        $this->assertSame(28_800, (int) $healthy->active_duration_seconds);
        $this->assertSame(50_000, (int) $open->active_duration_seconds);
        $this->assertSame(3_600, (int) $equal->active_duration_seconds);
        $this->assertSame(0, (int) $healthy->overtime_seconds);
        $this->assertSame(0, (int) $equal->overtime_seconds);
    }

    public function test_second_run_is_idempotent(): void
    {
        $agent = $this->createScheduledAgent();

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-28',
            'login_at' => Carbon::parse('2026-07-28 09:56:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse('2026-07-28 18:26:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::AwayTimeout,
            'session_duration_seconds' => 30_600,
            'active_duration_seconds' => 49_715,
            'idle_duration_seconds' => 100,
            'overtime_seconds' => 120_420,
            'last_activity_at' => Carbon::parse('2026-07-28 18:10:00', 'Asia/Kolkata'),
            'last_tick_at' => Carbon::parse('2026-07-28 23:59:59', 'Asia/Kolkata'),
            'on_time_login' => true,
        ]);

        $this->artisan('attendance:repair-corrupted-sessions')
            ->expectsOutputToContain('Sessions repaired:')
            ->assertSuccessful();

        $this->artisan('attendance:repair-corrupted-sessions')
            ->expectsOutputToContain('Active duration sessions repaired: 0')
            ->expectsOutputToContain('Overtime sessions repaired: 0')
            ->expectsOutputToContain('Sessions repaired: 0')
            ->expectsOutputToContain('Date range reconciled: none')
            ->assertSuccessful();

        $session = WorkSession::query()->where('user_id', $agent->id)->first();
        $this->assertNotNull($session);
        $this->assertSame(30_600, (int) $session->active_duration_seconds);
        $this->assertSame(100, (int) $session->idle_duration_seconds);
        $this->assertSame(
            app(PresenceEngineService::class)->recalculateOvertimeSeconds($session),
            (int) $session->overtime_seconds,
        );
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
