<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServiceCaseAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config(['service_case_assignment.automation_grace_period_enabled' => false]);
    }

    private function configureAssignmentSettings(
        int $dayAdminId,
        int $nightAdminId,
        int $fallback1Id = 0,
        int $fallback2Id = 0,
    ): void {
        app(SettingService::class)->setMany([
            'assignment.timezone' => 'Asia/Kolkata',
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $dayAdminId,
            'assignment.night_shift_admin_user_id' => (string) $nightAdminId,
            'assignment.fallback_admin_1_user_id' => $fallback1Id > 0 ? (string) $fallback1Id : '',
            'assignment.fallback_admin_2_user_id' => $fallback2Id > 0 ? (string) $fallback2Id : '',
        ]);
    }

    private function createAdminUser(string $email, string $name, bool $active = true): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => $active,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createIncidentForAssignmentTest(?User $actor = null): Incident
    {
        $actor ??= User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'RD-RR-'.uniqid(),
            'serial_number' => 'SN-RR-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Round robin test',
            'description' => 'Round robin test.',
            'status' => 'open',
            'created_by' => $actor->id,
        ]);
    }

    private function createAgentUser(string $email, string $name, bool $active = true): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => $active,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    public function test_round_robin_assigns_first_active_agent_when_cursor_is_zero(): void
    {
        $agentA = $this->createAgentUser('agent-a@test.com', 'Agent Alpha');
        $this->createAgentUser('agent-b@test.com', 'Agent Beta');

        $incident = $this->createIncidentForAssignmentTest();

        $result = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $incident->creator);

        $this->assertSame($agentA->id, $result->assigned_to_user_id);
    }

    public function test_round_robin_advances_cursor_and_wraps_to_first_agent(): void
    {
        $agentA = $this->createAgentUser('agent-a@test.com', 'Agent Alpha');
        $agentB = $this->createAgentUser('agent-b@test.com', 'Agent Beta');
        $service = app(ServiceCaseAssignmentService::class);
        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $first = $this->createIncidentForAssignmentTest($actor);
        $second = $this->createIncidentForAssignmentTest($actor);
        $third = $this->createIncidentForAssignmentTest($actor);

        $this->assertSame($agentA->id, $service->assignOnCreate($first, $actor)->assigned_to_user_id);
        $this->assertSame($agentB->id, $service->assignOnCreate($second, $actor)->assigned_to_user_id);
        $this->assertSame($agentA->id, $service->assignOnCreate($third, $actor)->assigned_to_user_id);
    }

    public function test_round_robin_skips_inactive_agents(): void
    {
        $this->createAgentUser('inactive@test.com', 'Inactive Agent', active: false);
        $activeAgent = $this->createAgentUser('active@test.com', 'Active Agent');

        $incident = $this->createIncidentForAssignmentTest();
        $result = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $incident->creator);

        $this->assertSame($activeAgent->id, $result->assigned_to_user_id);
    }

    public function test_assign_on_create_leaves_case_unassigned_when_no_agents_exist(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-UNASSIGNED-1',
            'serial_number' => 'SN-UNASSIGNED-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Unassigned test',
            'description' => 'Unassigned test.',
            'status' => 'open',
            'created_by' => $actor->id,
        ]);

        $result = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident->fresh(), $actor);

        $this->assertNull($result->assigned_to_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.unassigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_assign_on_create_is_idempotent_when_already_assigned(): void
    {
        $agent = $this->createAgentUser('assigned@test.com', 'Assigned Agent');
        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-IDEMPOTENT-1',
            'serial_number' => 'SN-IDEMPOTENT-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Idempotent test',
            'description' => 'Idempotent test.',
            'status' => 'open',
            'assigned_to_user_id' => $agent->id,
            'created_by' => $actor->id,
        ]);

        $service = app(ServiceCaseAssignmentService::class);
        $result = $service->assignOnCreate($incident->fresh(), $actor);

        $this->assertSame($agent->id, $result->assigned_to_user_id);
        $this->assertSame(0, AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->whereIn('event', ['service_case.assigned', 'service_case.unassigned'])
            ->count());
    }

    public function test_day_shift_assigns_configured_day_admin(): void
    {
        $avinash = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $this->configureAssignmentSettings($avinash->id, $avinash->id);
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $assignee = app(ServiceCaseAssignmentService::class)->resolveAssignee();

        $this->assertTrue($assignee->is($avinash));

        Carbon::setTestNow();
    }

    public function test_after_hours_assigns_configured_after_hours_admin(): void
    {
        $shipra = $this->createAdminUser('shipra@radiumbox.com', 'Shipra Kumari');
        $this->configureAssignmentSettings(99999, $shipra->id);
        Carbon::setTestNow(Carbon::parse('2026-06-24 20:15:00', 'Asia/Kolkata'));

        $assignee = app(ServiceCaseAssignmentService::class)->resolveAssignee();

        $this->assertTrue($assignee->is($shipra));

        Carbon::setTestNow();
    }

    public function test_falls_back_to_dileep_when_primary_admin_missing(): void
    {
        $dileep = $this->createAdminUser('dileep@radiumbox.com', 'Dileep Admin');
        $this->configureAssignmentSettings(99999, 99998, $dileep->id);
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $assignee = app(ServiceCaseAssignmentService::class)->resolveAssignee();

        $this->assertTrue($assignee->is($dileep));

        Carbon::setTestNow();
    }

    public function test_falls_back_to_local_admin_when_dileep_unavailable(): void
    {
        $dileep = $this->createAdminUser('dileep@radiumbox.com', 'Dileep Admin', active: false);
        $localAdmin = $this->createAdminUser('admin@radium.local', 'Local Admin');
        $this->configureAssignmentSettings(99999, 99998, $dileep->id, $localAdmin->id);
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $assignee = app(ServiceCaseAssignmentService::class)->resolveAssignee();

        $this->assertTrue($assignee->is($localAdmin));

        Carbon::setTestNow();
    }

    public function test_throws_when_no_valid_admin_exists(): void
    {
        $this->configureAssignmentSettings(99999, 99998);
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $this->expectException(ValidationException::class);

        app(ServiceCaseAssignmentService::class)->resolveAssignee();

        Carbon::setTestNow();
    }

    public function test_quick_create_with_serial_assigns_shift_admin_when_grace_period_enabled(): void
    {
        config(['service_case_assignment.automation_grace_period_enabled' => true]);

        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $this->configureAssignmentSettings($admin->id, $admin->id);
        $this->createAgentUser('agent-a@test.com', 'Agent Alpha');

        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $response = $this->actingAs($creator)->post(route('service-requests.quick.store'), [
            'order_id' => 'RD-ASSIGN-1',
            'serial_number' => 'SN-ASSIGN-1',
            'product' => 'MFS 110',
            'source' => IncidentSource::Call->value,
        ]);

        $incident = Incident::query()->first();
        $this->assertNotNull($incident);

        $response->assertRedirect(route('dashboard'));

        $this->assertSame($admin->id, $incident->assignee?->id);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.assigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_admin_can_manually_reassign_service_case(): void
    {
        $avinash = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $shipra = $this->createAdminUser('shipra@radiumbox.com', 'Shipra Kumari');

        $admin = User::factory()->create(['name' => 'Other Admin']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-REASSIGN-1',
            'serial_number' => 'SN-REASSIGN-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Reassign test',
            'description' => 'Reassign test.',
            'status' => 'open',
            'assigned_to_user_id' => $avinash->id,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->patch(route('incidents.assignment.update', $incident), [
                'assigned_to_user_id' => $shipra->id,
            ])
            ->assertRedirect(route('incidents.show', $incident))
            ->assertSessionHas('status', 'service-case-reassigned');

        $incident->refresh();
        $this->assertSame($shipra->id, $incident->assigned_to_user_id);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.reassigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_closed_service_case_cannot_be_reassigned(): void
    {
        $avinash = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $shipra = $this->createAdminUser('shipra@radiumbox.com', 'Shipra Kumari');

        $admin = User::factory()->create(['name' => 'Other Admin']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-REASSIGN-CLOSED',
            'serial_number' => 'SN-REASSIGN-CLOSED',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Closed case',
            'description' => 'Closed case.',
            'status' => IncidentStatus::Closed->value,
            'assigned_to_user_id' => $avinash->id,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->patch(route('incidents.assignment.update', $incident), [
                'assigned_to_user_id' => $shipra->id,
            ])
            ->assertForbidden();

        $this->assertSame($avinash->id, $incident->fresh()->assigned_to_user_id);
    }

    public function test_dashboard_displays_owner_first_name(): void
    {
        $avinash = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');

        $agent = User::factory()->create(['name' => 'Agent User']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-OWNER-1',
            'serial_number' => 'SN-OWNER-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-OWNER-1',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Owner display test',
            'description' => 'Owner display test.',
            'status' => 'open',
            'assigned_to_user_id' => $avinash->id,
            'created_by' => $agent->id,
        ]);

        $this->actingAs($avinash)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Owner', false)
            ->assertSee('aria-label="Owner: Avinash Jha"', false)
            ->assertSee('data-bs-title="Avinash Jha"', false);
    }

    public function test_service_case_detail_shows_assigned_to_first_name(): void
    {
        $shipra = $this->createAdminUser('shipra@radiumbox.com', 'Shipra Kumari');

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-DETAIL-1',
            'serial_number' => 'SN-DETAIL-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-DETAIL-1',
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => 'Detail assignee test',
            'description' => 'Detail assignee test.',
            'status' => 'open',
            'assigned_to_user_id' => $shipra->id,
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('incidents.show', $incident))
            ->assertOk()
            ->assertSee('Assigned:')
            ->assertSee('Shipra');
    }
}
