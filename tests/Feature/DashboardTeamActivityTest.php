<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\LeaveRequestStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\LeaveRequest;
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

class DashboardTeamActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['dashboard-team-activity.enabled' => true]);
    }

    public function test_team_activity_refresh_returns_panel_html_for_authorized_users(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);
        $admin->update(['availability_status' => TeamAvailabilityStatus::Available]);

        WorkSession::query()->create([
            'user_id' => $admin->id,
            'work_date' => now()->toDateString(),
            'login_at' => now()->subHours(2),
            'last_activity_at' => now(),
            'active_duration_seconds' => 7200,
        ]);

        [$incident] = $this->createIncident($admin, [
            'customer_name' => 'Team Customer',
        ]);

        AuditLog::query()->create([
            'user_id' => $admin->id,
            'event' => 'service_case.assigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'new_values' => [
                'assigned_to_user_id' => $admin->id,
            ],
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.team-activity'));

        $response->assertOk()
            ->assertJsonPath('empty', false)
            ->assertJsonStructure(['html', 'generated_at', 'agent_count']);

        $html = (string) $response->json('html');

        $this->assertStringContainsString('Team Activity', $html);
        $this->assertStringContainsString('data-team-activity-refresh-url', $html);
        $this->assertStringContainsString((string) $admin->name, $html);
        $this->assertStringContainsString('Assigned', $html);
        $this->assertStringContainsString('team-activity-kpi-count', $html);
        $this->assertStringContainsString('team-activity-kpi-label', $html);
        $this->assertStringContainsString('Orders Activated', $html);
        $this->assertStringContainsString('Active', $html);
        $this->assertStringContainsString('team-activity-grid-header', $html);
        $this->assertStringContainsString('team-activity-avatar', $html);
        $this->assertStringContainsString('team-activity-live-presence', $html);
        $this->assertStringContainsString('team-activity-status__dot', $html);
    }

    public function test_team_activity_refresh_returns_empty_payload_without_permission(): void
    {
        $user = User::factory()->create();
        // Permission-less user: no role assigned.
        $this->actingAs($user)
            ->getJson(route('dashboard.team-activity'))
            ->assertOk()
            ->assertJsonPath('empty', true)
            ->assertJsonPath('html', null);
    }

    public function test_team_activity_refresh_returns_panel_html_for_agents(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $agent->update(['availability_status' => TeamAvailabilityStatus::Available]);

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => now()->toDateString(),
            'login_at' => now()->subHours(2),
            'last_activity_at' => now(),
            'active_duration_seconds' => 7200,
        ]);

        $response = $this->actingAs($agent)
            ->getJson(route('dashboard.team-activity'));

        $response->assertOk()
            ->assertJsonPath('empty', false)
            ->assertJsonStructure(['html', 'generated_at', 'agent_count']);

        $html = (string) $response->json('html');

        $this->assertStringContainsString('Team Activity', $html);
        $this->assertStringContainsString('data-team-activity-refresh-url', $html);
        $this->assertStringContainsString((string) $agent->name, $html);
    }

    public function test_agents_do_not_gain_audit_logs_access_via_team_activity(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->assertTrue($agent->can('teamActivity.view'));
        $this->assertTrue($agent->can(RolePermissionSeeder::PERMISSION_TEAM_ACTIVITY_VIEW));
        $this->assertTrue($agent->can(RolePermissionSeeder::PERMISSION_WORKFORCE_VIEW));
        $this->assertFalse($agent->can('audit-logs.view'));
    }

    public function test_team_activity_view_is_derived_for_roles_with_workforce_view(): void
    {
        $roles = \Spatie\Permission\Models\Role::query()
            ->with('permissions')
            ->get();

        $this->assertNotEmpty($roles);

        foreach ($roles as $role) {
            $permissions = $role->permissions->pluck('name');

            if (! $permissions->contains(RolePermissionSeeder::PERMISSION_WORKFORCE_VIEW)) {
                continue;
            }

            $this->assertTrue(
                $permissions->contains(RolePermissionSeeder::PERMISSION_TEAM_ACTIVITY_VIEW),
                "Role [{$role->name}] has workforce.view but is missing team-activity.view.",
            );
        }
    }

    public function test_dashboard_page_includes_team_activity_attributes(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);
        $admin->update(['availability_status' => TeamAvailabilityStatus::Available]);

        WorkSession::query()->create([
            'user_id' => $admin->id,
            'work_date' => now()->toDateString(),
            'login_at' => now()->subHour(),
            'last_activity_at' => now(),
            'active_duration_seconds' => 3600,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-team-activity-refresh-url', false)
            ->assertSee('data-team-activity-poll-interval-ms', false)
            ->assertSee(route('dashboard.team-activity'), false)
            ->assertSee('Team Activity', false)
            ->assertDontSee('data-activity-refresh-url', false);
    }

    public function test_expanded_agent_history_is_included_in_refresh_payload(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);
        $admin->update(['availability_status' => TeamAvailabilityStatus::Available]);

        WorkSession::query()->create([
            'user_id' => $admin->id,
            'work_date' => now()->toDateString(),
            'login_at' => now()->subHours(3),
            'last_activity_at' => now(),
            'active_duration_seconds' => 10800,
        ]);

        [$incident] = $this->createIncident($admin);

        foreach (['service_case.assigned', 'service_case.status_changed', 'service_case.escalated'] as $event) {
            AuditLog::query()->create([
                'user_id' => $admin->id,
                'event' => $event,
                'auditable_type' => $incident->getMorphClass(),
                'auditable_id' => $incident->id,
                'new_values' => [],
            ]);
        }

        $html = (string) $this->actingAs($admin)
            ->getJson(route('dashboard.team-activity', [
                'expanded' => [$admin->id],
            ]))
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('team-activity-history', $html);
        $this->assertStringContainsString('is-expanded', $html);
    }

    public function test_operational_roster_includes_all_attendance_tracked_users(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $onDuty = $this->createTrackedAgent('On Duty Agent', startSession: true);
        $offDuty = $this->createTrackedAgent('Off Duty Agent');
        $onLeave = $this->createTrackedAgent('Leave Agent');
        LeaveRequest::query()->create([
            'user_id' => $onLeave->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'reason' => 'Annual Leave',
            'status' => LeaveRequestStatus::Approved,
        ]);

        $superadmin = User::factory()->create(['is_active' => true, 'name' => 'Super Admin']);
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $panel = app(TeamActivityPanelService::class)->build();
        $names = array_map(static fn ($agent) => $agent->name, $panel->agents);

        $this->assertFalse($panel->empty);
        $this->assertCount(4, $panel->agents);
        $this->assertContains('On Duty Agent', $names);
        $this->assertContains('Off Duty Agent', $names);
        $this->assertContains('Leave Agent', $names);
        $this->assertContains('IRA', $names);
        $this->assertNotContains('Super Admin', $names);

        Carbon::setTestNow();
    }

    public function test_off_duty_and_leave_agents_remain_visible_in_refresh_payload(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $offDuty = $this->createTrackedAgent('Off Duty Agent');
        $onLeave = $this->createTrackedAgent('Leave Agent');
        LeaveRequest::query()->create([
            'user_id' => $onLeave->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'reason' => 'Annual Leave',
            'status' => LeaveRequestStatus::Approved,
        ]);

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $html = (string) $this->actingAs($viewer)
            ->getJson(route('dashboard.team-activity'))
            ->assertOk()
            ->assertJsonPath('empty', false)
            ->assertJsonPath('agent_count', 3)
            ->json('html');

        $this->assertStringContainsString('Off Duty Agent', $html);
        $this->assertStringContainsString('Leave Agent', $html);
        $this->assertStringContainsString('team-activity-live-presence__code">LV<', $html);
        $this->assertStringContainsString('team-activity-live-presence__code">NLI<', $html);
        $this->assertStringContainsString('title="Annual Leave"', $html);
        $this->assertStringContainsString('aria-label="On Leave · Annual Leave"', $html);

        Carbon::setTestNow();
    }

    public function test_not_started_shift_agent_remains_visible_before_shift(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 08:00:00', 'Asia/Kolkata'));

        $agent = $this->createTrackedAgent('Early Agent');

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $html = (string) $this->actingAs($viewer)
            ->getJson(route('dashboard.team-activity'))
            ->assertOk()
            ->assertJsonPath('agent_count', 2)
            ->json('html');

        $this->assertStringContainsString('Early Agent', $html);
        $this->assertStringContainsString('Shift Not Started', $html);
        $this->assertStringContainsString('Shift starts 9:00 AM', $html);

        Carbon::setTestNow();
    }

    public function test_supervisor_friendly_latest_activity_labels_remain(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $admin = $this->createTrackedAgent('Active Agent', startSession: true);
        [$incident] = $this->createIncident($admin);

        AuditLog::query()->create([
            'user_id' => $admin->id,
            'event' => 'workforce.leave.approved',
            'auditable_type' => $admin->getMorphClass(),
            'auditable_id' => $admin->id,
            'new_values' => [],
            'created_at' => now(),
        ]);

        AuditLog::query()->create([
            'user_id' => $admin->id,
            'event' => 'service_case.status_changed',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'new_values' => [],
            'created_at' => now()->addMinute(),
        ]);

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $html = (string) $this->actingAs($viewer)
            ->getJson(route('dashboard.team-activity'))
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('team-activity-latest-event__label">Status<', $html);
        $this->assertStringNotContainsString('team-activity-latest-event__label">Status Changed<', $html);
        $this->assertStringContainsString('Active', $html);

        Carbon::setTestNow();
    }

    private function createTrackedAgent(string $name, bool $startSession = false): User
    {
        $user = User::factory()->create([
            'name' => $name,
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

        if ($startSession) {
            app(PresenceEngineService::class)->startSession($user->fresh(['workSchedule', 'roles']));
        }

        return $user->fresh(['workSchedule', 'roles']);
    }

    /**
     * @param  array{customer_name?: string}  $orderOverrides
     * @return array{0: Incident, 1: Order}
     */
    private function createIncident(User $user, array $orderOverrides = []): array
    {
        $order = Order::query()->create([
            'order_id' => 'RD1000100',
            'serial_number' => 'SN-0100',
            'customer_name' => $orderOverrides['customer_name'] ?? 'Test Customer',
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
            'title' => 'Team activity feed test case',
            'description' => 'Team activity feed test case.',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        return [$incident, $order];
    }
}
