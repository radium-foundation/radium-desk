<?php

namespace Tests\Unit\Dashboard;

use App\Models\User;
use App\Models\WorkSession;
use App\Services\Dashboard\TeamActivityPresenceMetricsService;
use App\Services\Operations\PresenceEngineService;
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

    public function test_metrics_use_session_duration_not_active_duration(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => now()->toDateString(),
            'login_at' => now()->subHours(2),
            'logout_at' => now()->subHour(),
            'session_duration_seconds' => 3600,
            'active_duration_seconds' => 999999,
        ]);

        $metrics = app(TeamActivityPresenceMetricsService::class)->forUsers([$agent->id])[$agent->id];

        $this->assertSame('1h 0m', $metrics->todayDurationLabel);
        $this->assertFalse($metrics->hasOpenSession);
    }

    public function test_open_session_elapsed_is_added_to_today_total(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => now()->toDateString(),
            'login_at' => now()->subHours(3),
            'logout_at' => now()->subHours(2),
            'session_duration_seconds' => 3600,
        ]);

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => now()->toDateString(),
            'login_at' => now()->subMinutes(30),
            'logout_at' => null,
        ]);

        $metrics = app(TeamActivityPresenceMetricsService::class)->forUsers([$agent->id])[$agent->id];

        $this->assertSame('1h 30m', $metrics->todayDurationLabel);
        $this->assertSame('30m', $metrics->currentDurationLabel);
        $this->assertSame(2, $metrics->sessionsToday);
        $this->assertTrue($metrics->hasOpenSession);
    }
}
