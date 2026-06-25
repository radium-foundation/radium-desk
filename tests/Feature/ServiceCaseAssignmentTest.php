<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
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

    public function test_quick_create_automatically_assigns_owner_by_time(): void
    {
        $avinash = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $this->configureAssignmentSettings($avinash->id, $avinash->id);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Carbon::setTestNow(Carbon::parse('2026-06-24 10:30:00', 'Asia/Kolkata'));

        $response = $this->actingAs($agent)->post(route('service-requests.quick.store'), [
            'order_id' => 'RD-ASSIGN-1',
            'serial_number' => 'SN-ASSIGN-1',
            'product' => 'MFS 110',
            'source' => IncidentSource::Call->value,
        ]);

        $incident = Incident::query()->first();
        $this->assertNotNull($incident);

        $response->assertRedirect(route('incidents.show', $incident));

        $this->assertSame('avinash@radiumbox.com', $incident->assignee?->email);

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

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Owner', false)
            ->assertSee('>Avinash</td>', false);
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
