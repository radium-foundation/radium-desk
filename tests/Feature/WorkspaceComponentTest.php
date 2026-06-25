<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceComponentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function createAdminUser(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createIncident(User $creator, array $overrides = []): Incident
    {
        $order = Order::query()->create([
            'order_id' => $overrides['order_id'] ?? 'ORD-WS-1',
            'serial_number' => 'SN-WS-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $overrides['reference_no'] ?? app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => $overrides['title'] ?? 'Workspace component test case',
            'description' => $overrides['description'] ?? 'Workspace component test description.',
            'status' => $overrides['status'] ?? IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            ...$overrides,
        ]);
    }

    public function test_authorized_user_receives_assign_component_html_fragment(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin);

        $this->actingAs($admin)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'assign',
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('id="assignModalLabel"', false)
            ->assertSee('id="modal_assigned_to_user_id"', false)
            ->assertSee('Assign Service Case', false)
            ->assertDontSee('<!DOCTYPE html>', false);
    }

    public function test_authorized_user_receives_remark_component_html_fragment(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createIncident($agent);

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'remark',
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('id="remarkModalLabel"', false)
            ->assertSee('id="modal_remark_body"', false)
            ->assertSee('Add Remark', false);
    }

    public function test_unauthorized_user_cannot_load_assign_component(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createIncident($agent);

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'assign',
            ]))
            ->assertForbidden();
    }

    public function test_invalid_component_returns_not_found(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin);

        $this->actingAs($admin)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'invalid-component',
            ]))
            ->assertNotFound();
    }

    public function test_timeline_component_returns_html_fragment(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createIncident($agent);

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'timeline',
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('id="activity-timeline"', false)
            ->assertSee('Activity Timeline', false);
    }
}
