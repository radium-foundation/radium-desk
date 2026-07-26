<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\TeamActivityStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Dashboard\TeamActivityPanelService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\PresenceEngineService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTeamActivityPresenceV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['dashboard-team-activity.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-07-26 17:40:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_open_session_overrides_auto_logged_out_status(): void
    {
        $agent = $this->createWeeklyOffAgent();
        $presence = app(PresenceEngineService::class);

        foreach (range(1, 7) as $index) {
            $loginAt = now()->startOfDay()->addHours(8)->addMinutes($index * 45);
            $logoutAt = $loginAt->copy()->addMinutes(30);

            WorkSession::query()->create([
                'user_id' => $agent->id,
                'work_date' => now()->toDateString(),
                'login_at' => $loginAt,
                'logout_at' => $logoutAt,
                'ended_reason' => WorkSessionEndReason::AwayTimeout,
                'session_duration_seconds' => 1800,
            ]);
        }

        $presence->startSession($agent->fresh(['workSchedule', 'roles']), now()->subMinutes(20));

        $agent->forceFill([
            'availability_status' => TeamAvailabilityStatus::Offline,
        ])->save();

        [$incident, $order] = $this->createIncident($agent);
        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'service_case.reassigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => now()->subMinute(),
        ]);

        $row = $this->agentRow($agent);

        $this->assertSame(TeamActivityStatus::Working, $row->status);
        $this->assertNotSame(TeamActivityStatus::AutoLogout, $row->status);
        $this->assertNotNull($row->latest);
        $this->assertStringContainsString('Reassigned', $row->latest->label);
    }

    public function test_weekly_off_badge_shown_with_working_status(): void
    {
        $agent = $this->createWeeklyOffAgent();
        app(PresenceEngineService::class)->startSession($agent->fresh(['workSchedule', 'roles']));

        $row = $this->agentRow($agent);

        $this->assertSame(TeamActivityStatus::Working, $row->status);
        $this->assertSame('Weekly Off', $row->calendarBadge);
    }

    public function test_today_duration_includes_previous_sessions(): void
    {
        $agent = $this->createTrackedAgent();

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => now()->toDateString(),
            'login_at' => now()->startOfDay()->addHours(9),
            'logout_at' => now()->startOfDay()->addHours(10),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'session_duration_seconds' => 3600,
        ]);

        app(PresenceEngineService::class)->startSession($agent->fresh(['workSchedule', 'roles']), now()->subMinutes(20));

        $row = $this->agentRow($agent);

        $this->assertSame('1h 20m', $row->todayDurationLabel);
    }

    public function test_current_duration_uses_open_session(): void
    {
        $agent = $this->createTrackedAgent();
        app(PresenceEngineService::class)->startSession($agent->fresh(['workSchedule', 'roles']), now()->subMinutes(20));

        $row = $this->agentRow($agent);

        $this->assertSame('20m', $row->currentDurationLabel);
    }

    public function test_session_count_is_correct(): void
    {
        $agent = $this->createTrackedAgent();
        $presence = app(PresenceEngineService::class);

        foreach (range(1, 7) as $index) {
            $loginAt = now()->startOfDay()->addHours(8)->addMinutes($index * 30);
            WorkSession::query()->create([
                'user_id' => $agent->id,
                'work_date' => now()->toDateString(),
                'login_at' => $loginAt,
                'logout_at' => $loginAt->copy()->addMinutes(20),
                'ended_reason' => WorkSessionEndReason::AwayTimeout,
                'session_duration_seconds' => 1200,
            ]);
        }

        $presence->startSession($agent->fresh(['workSchedule', 'roles']), now()->subMinutes(5));

        $row = $this->agentRow($agent);

        $this->assertSame(8, $row->sessionsToday);
    }

    public function test_latest_activity_never_contradicts_status(): void
    {
        $agent = $this->createWeeklyOffAgent();
        app(PresenceEngineService::class)->startSession($agent->fresh(['workSchedule', 'roles']), now()->subMinutes(10));

        WorkSession::query()
            ->where('user_id', $agent->id)
            ->whereNull('logout_at')
            ->update([
                'logout_at' => now()->subMinutes(15),
                'ended_reason' => WorkSessionEndReason::AwayTimeout,
                'session_duration_seconds' => 600,
            ]);

        app(PresenceEngineService::class)->startSession($agent->fresh(['workSchedule', 'roles']), now()->subMinutes(5));

        [$incident] = $this->createIncident($agent);
        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'service_case.reassigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => now()->subMinute(),
        ]);

        $row = $this->agentRow($agent);

        $this->assertSame(TeamActivityStatus::Working, $row->status);
        $this->assertNotNull($row->latest);
        $this->assertStringContainsString('Reassigned', $row->latest->label);
    }

    private function agentRow(User $agent): \App\Data\TeamActivityAgentRow
    {
        $panel = app(TeamActivityPanelService::class)->build();
        $row = collect($panel->agents)->firstWhere('id', $agent->id);

        $this->assertNotNull($row);

        return $row;
    }

    private function createTrackedAgent(): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
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

    private function createWeeklyOffAgent(): User
    {
        $user = $this->createTrackedAgent();

        TeamMemberWorkSchedule::query()
            ->where('user_id', $user->id)
            ->update([
                'weekly_off_days' => [now()->dayOfWeek],
            ]);

        return $user->fresh(['workSchedule', 'roles']);
    }

    /**
     * @return array{0: Incident, 1: Order}
     */
    private function createIncident(User $user): array
    {
        $order = Order::query()->create([
            'order_id' => 'RD3462441',
            'serial_number' => 'SN-PRESENCE',
            'customer_name' => 'Presence Test Customer',
            'product_name' => 'RBX 110',
            'device_model' => 'RBX 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Presence test case',
            'description' => 'Presence test case.',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        return [$incident, $order];
    }
}
