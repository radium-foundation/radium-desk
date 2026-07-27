<?php

namespace Tests\Unit\Dashboard;

use App\Enums\WorkSessionEndReason;
use App\Enums\WorkSessionOrigin;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Dashboard\TeamActivityPresenceMetricsService;
use App\Services\Operations\AttendanceRegisterService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TeamActivityPresenceMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-07-26 12:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_today_uses_attendance_active_duration_not_session_wall_clock(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => now()->toDateString(),
            'login_at' => now()->subHours(2),
            'logout_at' => now()->subHour(),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'origin' => WorkSessionOrigin::Login,
            'is_attributable' => true,
            'session_duration_seconds' => 7200,
            'active_duration_seconds' => 1800,
        ]);

        app(AttendanceRegisterService::class)->refreshDay($agent, now()->startOfDay(), now());

        $metrics = app(TeamActivityPresenceMetricsService::class)->forUsers([$agent->id])[$agent->id];

        $this->assertSame('30m', $metrics->todayDurationLabel);
        $this->assertSame(1800, $metrics->todayDurationSeconds);
        $this->assertFalse($metrics->hasOpenSession);
    }

    public function test_current_session_remains_open_wall_clock_while_today_uses_attendance(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => now()->toDateString(),
            'login_at' => now()->subHours(3),
            'logout_at' => now()->subHours(2),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'origin' => WorkSessionOrigin::Login,
            'is_attributable' => true,
            'session_duration_seconds' => 3600,
            'active_duration_seconds' => 1200,
        ]);

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => now()->toDateString(),
            'login_at' => now()->subMinutes(30),
            'logout_at' => null,
            'origin' => WorkSessionOrigin::Browser,
            'is_attributable' => true,
            'active_duration_seconds' => 600,
            'last_activity_at' => now()->subMinutes(1),
            'last_tick_at' => now()->subMinutes(1),
        ]);

        app(AttendanceRegisterService::class)->refreshDay($agent, now()->startOfDay(), now());

        $metrics = app(TeamActivityPresenceMetricsService::class)->forUsers([$agent->id])[$agent->id];

        $this->assertSame('30m', $metrics->currentDurationLabel);
        $this->assertTrue($metrics->hasOpenSession);
        $this->assertSame($metrics->todayDurationSeconds, $metrics->todayDurationSeconds);
        $this->assertGreaterThanOrEqual(1200, $metrics->todayDurationSeconds);
    }

    public function test_non_attributable_sessions_are_excluded_from_today_hours(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => now()->toDateString(),
            'login_at' => now()->subHours(5),
            'logout_at' => now()->subHours(1),
            'ended_reason' => WorkSessionEndReason::AwayTimeout,
            'origin' => WorkSessionOrigin::Assignment,
            'is_attributable' => false,
            'session_duration_seconds' => 14400,
            'active_duration_seconds' => 14400,
        ]);

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => now()->toDateString(),
            'login_at' => now()->subHour(),
            'logout_at' => now()->subMinutes(30),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'origin' => WorkSessionOrigin::Login,
            'is_attributable' => true,
            'session_duration_seconds' => 1800,
            'active_duration_seconds' => 900,
        ]);

        app(AttendanceRegisterService::class)->refreshDay($agent, now()->startOfDay(), now());

        $metrics = app(TeamActivityPresenceMetricsService::class)->forUsers([$agent->id])[$agent->id];

        $this->assertSame(900, $metrics->todayDurationSeconds);
        $this->assertSame('15m', $metrics->todayDurationLabel);
        $this->assertSame(1, $metrics->sessionsToday);
    }
}
