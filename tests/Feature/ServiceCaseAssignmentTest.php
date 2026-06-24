<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\ServiceCaseAssignmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ServiceCaseAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->configureAssignmentRules();
    }

    /**
     * @return array{avinash: User, shipra: User}
     */
    private function createConfiguredAssignees(): array
    {
        $avinash = User::factory()->create([
            'name' => 'Avinash Jha',
            'email' => 'avinash@test.com',
        ]);
        $avinash->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $shipra = User::factory()->create([
            'name' => 'Shipra Kumari',
            'email' => 'shipra@test.com',
        ]);
        $shipra->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return compact('avinash', 'shipra');
    }

    private function configureAssignmentRules(): void
    {
        config([
            'service_case_assignment.timezone' => 'Asia/Kolkata',
            'service_case_assignment.day_shift.start' => '09:00',
            'service_case_assignment.day_shift.end' => '18:30',
            'service_case_assignment.day_shift.assignee_email' => 'avinash@test.com',
            'service_case_assignment.after_hours.assignee_email' => 'shipra@test.com',
        ]);
    }

    public function test_day_shift_assigns_configured_day_admin(): void
    {
        $assignees = $this->createConfiguredAssignees();
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $assignee = app(ServiceCaseAssignmentService::class)->resolveAssignee();

        $this->assertTrue($assignee->is($assignees['avinash']));

        Carbon::setTestNow();
    }

    public function test_after_hours_assigns_configured_after_hours_admin(): void
    {
        $assignees = $this->createConfiguredAssignees();
        Carbon::setTestNow(Carbon::parse('2026-06-24 20:15:00', 'Asia/Kolkata'));

        $assignee = app(ServiceCaseAssignmentService::class)->resolveAssignee();

        $this->assertTrue($assignee->is($assignees['shipra']));

        Carbon::setTestNow();
    }

    public function test_quick_create_automatically_assigns_owner_by_time(): void
    {
        $this->createConfiguredAssignees();

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Carbon::setTestNow(Carbon::parse('2026-06-24 10:30:00', 'Asia/Kolkata'));

        $this->actingAs($agent)->post(route('service-requests.quick.store'), [
            'order_id' => 'RD-ASSIGN-1',
            'serial_number' => 'SN-ASSIGN-1',
            'product' => 'MFS 110',
            'source' => IncidentSource::Call->value,
        ])->assertRedirect(route('dashboard'));

        $incident = Incident::query()->first();
        $this->assertNotNull($incident);
        $this->assertSame('avinash@test.com', $incident->assignee?->email);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.assigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_admin_can_manually_reassign_service_case(): void
    {
        $assignees = $this->createConfiguredAssignees();

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
            'assigned_to_user_id' => $assignees['avinash']->id,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->patch(route('incidents.assignment.update', $incident), [
                'assigned_to_user_id' => $assignees['shipra']->id,
            ])
            ->assertRedirect(route('incidents.show', $incident))
            ->assertSessionHas('status', 'service-case-reassigned');

        $incident->refresh();
        $this->assertSame($assignees['shipra']->id, $incident->assigned_to_user_id);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.reassigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_dashboard_displays_owner_first_name(): void
    {
        $assignees = $this->createConfiguredAssignees();

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
            'assigned_to_user_id' => $assignees['avinash']->id,
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
        $assignees = $this->createConfiguredAssignees();

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
            'assigned_to_user_id' => $assignees['shipra']->id,
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('incidents.show', $incident))
            ->assertOk()
            ->assertSee('Assigned To')
            ->assertSee('Shipra');
    }
}
