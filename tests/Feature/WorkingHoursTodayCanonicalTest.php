<?php

namespace Tests\Feature;

use App\Enums\WorkSessionEndReason;
use App\Enums\WorkSessionOrigin;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Operations\AttendanceRegisterService;
use App\Services\Operations\PresenceEngineService;
use App\Services\Operations\WorkingHoursTodayService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class WorkingHoursTodayCanonicalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-07-27 15:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_working_hours_today_matches_attendance_active_duration(): void
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
            'session_duration_seconds' => 3600,
            'active_duration_seconds' => 2400,
        ]);

        app(AttendanceRegisterService::class)->refreshDay($agent, now()->startOfDay(), now());

        $hours = app(WorkingHoursTodayService::class)->forUser($agent);

        $this->assertSame(2400, $hours->activeDurationSeconds);
        $this->assertSame('40m', $hours->label);
    }

    public function test_start_session_sets_login_origin_as_attributable(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $session = app(PresenceEngineService::class)->startSession($agent);

        $this->assertNotNull($session);
        $this->assertSame(WorkSessionOrigin::Login, $session->origin);
        $this->assertTrue($session->is_attributable);
    }

    public function test_browser_create_if_missing_sets_browser_origin(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $session = app(PresenceEngineService::class)->recordActivity(
            $agent,
            createIfMissing: true,
        );

        $this->assertNotNull($session);
        $this->assertSame(WorkSessionOrigin::Browser, $session->origin);
        $this->assertTrue($session->is_attributable);
    }

    public function test_set_attribution_command_excludes_ghost_from_attendance(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $ghost = WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => now()->toDateString(),
            'login_at' => now()->subHours(6),
            'logout_at' => now()->subHours(2),
            'ended_reason' => WorkSessionEndReason::AwayTimeout,
            'origin' => WorkSessionOrigin::Migration,
            'is_attributable' => true,
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
        $this->assertSame(15300, app(WorkingHoursTodayService::class)->forUser($agent)->activeDurationSeconds);

        Artisan::call('work-sessions:set-attribution', [
            '--id' => $ghost->id,
            '--origin' => 'assignment',
            '--attributable' => '0',
            '--reconcile' => true,
        ]);

        $ghost->refresh();
        $this->assertSame(WorkSessionOrigin::Assignment, $ghost->origin);
        $this->assertFalse($ghost->is_attributable);
        $this->assertSame(900, app(WorkingHoursTodayService::class)->forUser($agent)->activeDurationSeconds);
    }
}
