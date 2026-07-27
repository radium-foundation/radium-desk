<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\PresenceStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\IncidentReferenceService;
use App\Services\Operations\PresenceEngineService;
use App\Services\Operations\TeamMemberActivityService;
use App\Services\Operations\WorkforceAuthorityService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\ServiceCaseEscalationService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PresenceSessionCreationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'service_case_assignment.automation_grace_period_enabled' => false,
            'service_case_assignment.round_robin_enabled' => true,
            'service_case_assignment.hardware_order.assignee_email' => 'sumit-offline@radiumbox.com',
            'service_case_assignment.escalation.level_1_email' => 'escalation-offline@radiumbox.com',
            'cashfree.system_user_email' => 'automation@radiumbox.com',
            'presence.active_threshold_minutes' => 5,
            'presence.away_timeout_minutes' => 15,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-27 10:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_hardware_assignment_to_offline_user_does_not_create_work_session(): void
    {
        $assignee = $this->createOfflineTrackedUser(
            'Sumit Offline',
            'sumit-offline@radiumbox.com',
            RolePermissionSeeder::ROLE_HARDWARE_TEAM,
        );
        $systemUser = $this->createAutomationActor();

        $incident = $this->createUnassignedIncident($systemUser, 'RDE281767');

        app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $systemUser);

        $this->assertSame($assignee->id, $incident->fresh()->assigned_to_user_id);
        $this->assertAssigneeHasNoPresenceSession($assignee);
    }

    public function test_manual_assignment_to_offline_user_does_not_create_work_session(): void
    {
        $assignee = $this->createOfflineTrackedUser(
            'Offline Agent',
            'offline-agent@radiumbox.com',
            RolePermissionSeeder::ROLE_AGENT,
        );
        $actor = $this->createAdminActor();
        $incident = $this->createUnassignedIncident($actor, 'RD-100001');

        app(ServiceCaseAssignmentService::class)->reassign($incident, $assignee, $actor);

        $this->assertSame($assignee->id, $incident->fresh()->assigned_to_user_id);
        $this->assertAssigneeHasNoPresenceSession($assignee);
    }

    public function test_automation_assignment_to_offline_user_does_not_create_work_session(): void
    {
        $assignee = $this->createOfflineTrackedUser(
            'Offline RR Agent',
            'offline-rr@radiumbox.com',
            RolePermissionSeeder::ROLE_AGENT,
        );
        $systemUser = $this->createAutomationActor();
        $incident = $this->createUnassignedIncident($systemUser, 'RD-100002');

        app(ServiceCaseAssignmentService::class)->assignWithAuditContext(
            incident: $incident,
            assignee: $assignee,
            actor: $systemUser,
            auditContext: [
                'assignment_method' => 'automation_test',
            ],
        );

        $this->assertSame($assignee->id, $incident->fresh()->assigned_to_user_id);
        $this->assertAssigneeHasNoPresenceSession($assignee);
    }

    public function test_escalation_to_offline_specialist_does_not_create_work_session(): void
    {
        $specialist = $this->createOfflineTrackedUser(
            'Offline Specialist',
            'escalation-offline@radiumbox.com',
            RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST,
        );
        $agent = $this->createOfflineTrackedUser(
            'Escalating Agent',
            'escalating-agent@radiumbox.com',
            RolePermissionSeeder::ROLE_AGENT,
        );
        // Actor may be present; assignee must still not get a session from escalation.
        app(PresenceEngineService::class)->startSession($agent);

        $incident = $this->createUnassignedIncident($agent, 'RD-100003');
        $incident->update(['assigned_to_user_id' => $agent->id]);

        app(ServiceCaseEscalationService::class)->escalate(
            incident: $incident->fresh(),
            actor: $agent,
            reason: 'Needs escalation specialist.',
        );

        $this->assertSame($specialist->id, $incident->fresh()->assigned_to_user_id);
        $this->assertAssigneeHasNoPresenceSession($specialist);
    }

    public function test_browser_login_still_creates_work_session(): void
    {
        $agent = $this->createOfflineTrackedUser(
            'Login Agent',
            'login-agent@radiumbox.com',
            RolePermissionSeeder::ROLE_AGENT,
            password: 'password',
        );

        $this->post(route('login'), [
            'email' => $agent->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $session = WorkSession::query()->where('user_id', $agent->id)->whereNull('logout_at')->first();

        $this->assertNotNull($session);
        $this->assertTrue($session->isOpen());
        $this->assertSame(TeamAvailabilityStatus::Available, $agent->fresh()->availability_status);
    }

    public function test_browser_heartbeat_refreshes_work_session(): void
    {
        $agent = $this->createOfflineTrackedUser(
            'Heartbeat Agent',
            'heartbeat-agent@radiumbox.com',
            RolePermissionSeeder::ROLE_AGENT,
        );
        $presence = app(PresenceEngineService::class);
        $session = $presence->startSession($agent);
        $session?->update([
            'last_activity_at' => now()->subMinutes(10),
            'last_tick_at' => now()->subMinutes(10),
        ]);

        $this->actingAs($agent)
            ->postJson(route('presence.heartbeat'))
            ->assertOk()
            ->assertJsonPath('presence.status', PresenceStatus::Active->value);

        $session->refresh();

        $this->assertSame('2026-07-27 10:00:00', $session->last_activity_at?->format('Y-m-d H:i:s'));
        $this->assertTrue($session->isOpen());
    }

    public function test_browser_heartbeat_can_create_work_session_when_missing(): void
    {
        $agent = $this->createOfflineTrackedUser(
            'Heartbeat Create Agent',
            'heartbeat-create@radiumbox.com',
            RolePermissionSeeder::ROLE_AGENT,
        );

        $this->assertNull(WorkSession::query()->where('user_id', $agent->id)->first());

        $this->actingAs($agent)
            ->postJson(route('presence.heartbeat'))
            ->assertOk();

        $this->assertNotNull(
            WorkSession::query()->where('user_id', $agent->id)->whereNull('logout_at')->first()
        );
    }

    public function test_browser_page_activity_can_create_work_session(): void
    {
        $agent = $this->createOfflineTrackedUser(
            'Middleware Agent',
            'middleware-agent@radiumbox.com',
            RolePermissionSeeder::ROLE_AGENT,
        );

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertNotNull(
            WorkSession::query()->where('user_id', $agent->id)->whereNull('logout_at')->first()
        );
    }

    public function test_business_activity_without_session_does_not_create_work_session(): void
    {
        $agent = $this->createOfflineTrackedUser(
            'Business Offline',
            'business-offline@radiumbox.com',
            RolePermissionSeeder::ROLE_AGENT,
        );

        app(TeamMemberActivityService::class)->recordStatusChange($agent);
        app(TeamMemberActivityService::class)->recordCaseAction($agent);
        app(TeamMemberActivityService::class)->recordCustomerCommunication($agent);

        $agent->refresh();

        $this->assertNull(WorkSession::query()->where('user_id', $agent->id)->first());
        $this->assertNotNull($agent->last_status_change_at);
        $this->assertNotNull($agent->last_case_action_at);
        $this->assertNotNull($agent->last_customer_communication_at);
        $this->assertSame(TeamAvailabilityStatus::Offline, $agent->availability_status);
        $this->assertFalse(app(WorkforceAuthorityService::class)->isPresent($agent));
    }

    public function test_business_activity_on_open_session_does_not_extend_presence(): void
    {
        $agent = $this->createOfflineTrackedUser(
            'Business Present',
            'business-present@radiumbox.com',
            RolePermissionSeeder::ROLE_AGENT,
        );
        $presence = app(PresenceEngineService::class);
        $session = $presence->startSession($agent);
        $staleAt = now()->subMinutes(10);
        $session?->update([
            'last_activity_at' => $staleAt,
            'last_tick_at' => $staleAt,
        ]);

        app(TeamMemberActivityService::class)->recordCustomerCommunication($agent);

        $session->refresh();
        $agent->refresh();

        $this->assertSame(1, $session->communication_events_count);
        $this->assertSame(
            $staleAt->format('Y-m-d H:i:s'),
            $session->last_activity_at?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(PresenceStatus::Idle, $presence->presenceStatus($agent));
        $this->assertNotNull($agent->last_customer_communication_at);
    }

    private function assertAssigneeHasNoPresenceSession(User $assignee): void
    {
        $assignee->refresh();

        $this->assertSame(
            0,
            WorkSession::query()->where('user_id', $assignee->id)->count(),
            'Assignment must not create a WorkSession for the assignee.',
        );
        $this->assertSame(TeamAvailabilityStatus::Offline, $assignee->availability_status);
        $this->assertFalse(app(WorkforceAuthorityService::class)->isPresent($assignee));
    }

    private function createOfflineTrackedUser(
        string $name,
        string $email,
        string $role,
        string $password = 'password',
    ): User {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt($password),
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Offline,
            'availability_updated_at' => now(),
            'last_active_at' => null,
            'last_case_action_at' => null,
            'last_status_change_at' => null,
            'last_customer_communication_at' => null,
        ]);
        $user->assignRole($role);

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

    private function createAdminActor(): User
    {
        $user = User::factory()->create([
            'name' => 'Admin Actor',
            'email' => 'admin-actor@radiumbox.com',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createAutomationActor(): User
    {
        $user = User::factory()->create([
            'name' => 'Automation',
            'email' => 'automation@radiumbox.com',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createUnassignedIncident(User $actor, string $orderId): Incident
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Presence policy test — '.$orderId,
            'description' => 'Presence policy test.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }
}
