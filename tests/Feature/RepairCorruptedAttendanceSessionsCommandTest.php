<?php

namespace Tests\Feature;

use App\Enums\WorkSessionEndReason;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use App\Models\WorkSession;
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
            'computed_at' => now()->subHour(),
            'source_version' => 1,
        ]);

        $this->artisan('attendance:repair-corrupted-sessions')
            ->expectsOutputToContain('Sessions scanned: 1')
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
            'last_activity_at' => Carbon::parse('2026-07-26 09:55:00', 'Asia/Kolkata'),
            'last_tick_at' => Carbon::parse('2026-07-26 10:00:00', 'Asia/Kolkata'),
            'on_time_login' => true,
        ]);

        $this->artisan('attendance:repair-corrupted-sessions')
            ->expectsOutputToContain('Sessions scanned: 0')
            ->expectsOutputToContain('Sessions repaired: 0')
            ->expectsOutputToContain('Date range reconciled: none')
            ->assertSuccessful();

        $healthy->refresh();
        $open->refresh();
        $equal->refresh();

        $this->assertSame(28_800, (int) $healthy->active_duration_seconds);
        $this->assertSame(50_000, (int) $open->active_duration_seconds);
        $this->assertSame(3_600, (int) $equal->active_duration_seconds);
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
            'last_activity_at' => Carbon::parse('2026-07-28 18:10:00', 'Asia/Kolkata'),
            'last_tick_at' => Carbon::parse('2026-07-28 23:59:59', 'Asia/Kolkata'),
            'on_time_login' => true,
        ]);

        $this->artisan('attendance:repair-corrupted-sessions')
            ->expectsOutputToContain('Sessions repaired: 1')
            ->assertSuccessful();

        $this->artisan('attendance:repair-corrupted-sessions')
            ->expectsOutputToContain('Sessions scanned: 0')
            ->expectsOutputToContain('Sessions repaired: 0')
            ->expectsOutputToContain('Date range reconciled: none')
            ->assertSuccessful();

        $session = WorkSession::query()->where('user_id', $agent->id)->first();
        $this->assertNotNull($session);
        $this->assertSame(30_600, (int) $session->active_duration_seconds);
        $this->assertSame(100, (int) $session->idle_duration_seconds);
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
