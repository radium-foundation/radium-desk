<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WorkspaceContext;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceAssignActionTest extends TestCase
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

    private function createIncident(User $creator, ?User $assignee = null, array $overrides = []): Incident
    {
        $order = Order::query()->create([
            'order_id' => $overrides['order_id'] ?? 'ORD-WSA-1',
            'serial_number' => 'SN-WSA-1',
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
            'title' => $overrides['title'] ?? 'Workspace assign action test',
            'description' => $overrides['description'] ?? 'Workspace assign action test description.',
            'status' => $overrides['status'] ?? IncidentStatus::Open,
            'assigned_to_user_id' => $assignee?->id,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            ...$overrides,
        ]);
    }

    public function test_assign_action_returns_service_case_refresh_payload(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $shipra = $this->createAdminUser('shipra@example.com', 'Shipra Kumari');
        $incident = $this->createIncident($admin, $admin);

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.assign', $incident), [
                'assigned_to_user_id' => $shipra->id,
                'workspace_context' => WorkspaceContext::ServiceCase->value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'assign')
            ->assertJsonPath('incident_id', $incident->id)
            ->assertJsonPath('meta.context', WorkspaceContext::ServiceCase->value)
            ->assertJsonPath('ui.close_workspace_host', true)
            ->assertJsonPath('refresh.kpis', false)
            ->assertJsonPath('refresh.replace_row', null)
            ->assertJsonStructure([
                'refresh' => [
                    'targets' => [
                        ['selector', 'html', 'strategy'],
                    ],
                ],
            ])
            ->assertJsonFragment(['selector' => '#activity-timeline'])
            ->assertJsonFragment(['selector' => '.service-case-header']);

        $this->assertSame($shipra->id, $incident->fresh()->assigned_to_user_id);
    }

    public function test_assign_action_returns_dashboard_refresh_payload(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $shipra = $this->createAdminUser('shipra@example.com', 'Shipra Kumari');
        $incident = $this->createIncident($admin, $admin);

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.assign', $incident), [
                'assigned_to_user_id' => $shipra->id,
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.context', WorkspaceContext::Dashboard->value)
            ->assertJsonPath('refresh.kpis', true)
            ->assertJsonStructure([
                'refresh' => [
                    'kpis_html' => ['kpi_strip_html'],
                    'replace_row' => ['incident_id', 'html', 'strategy'],
                ],
            ])
            ->assertJsonPath('refresh.replace_row.incident_id', $incident->id);
    }

    public function test_assign_action_is_forbidden_for_non_admin(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($agent);

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.assign', $incident), [
                'assigned_to_user_id' => $admin->id,
                'workspace_context' => WorkspaceContext::ServiceCase->value,
            ])
            ->assertForbidden();
    }

    public function test_assign_action_returns_validation_response_with_fragment(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin);

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.assign', $incident), [
                'assigned_to_user_id' => 99999,
                'workspace_context' => WorkspaceContext::ServiceCase->value,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('action', 'assign')
            ->assertJsonPath('ui.close_workspace_host', false)
            ->assertJsonStructure([
                'errors' => ['assigned_to_user_id'],
                'refresh' => [
                    'fragments' => [
                        ['component', 'target', 'html', 'strategy'],
                    ],
                ],
            ])
            ->assertJsonPath('refresh.fragments.0.component', 'assign');
    }

    public function test_assign_action_rejects_closed_service_case(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $shipra = $this->createAdminUser('shipra@example.com', 'Shipra Kumari');
        $incident = $this->createIncident($admin, $admin, [
            'status' => IncidentStatus::Closed,
        ]);

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.assign', $incident), [
                'assigned_to_user_id' => $shipra->id,
                'workspace_context' => WorkspaceContext::ServiceCase->value,
            ])
            ->assertForbidden();

        $this->assertSame($admin->id, $incident->fresh()->assigned_to_user_id);
    }

    public function test_legacy_assign_route_still_works(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $shipra = $this->createAdminUser('shipra@example.com', 'Shipra Kumari');
        $incident = $this->createIncident($admin, $admin);

        $this->actingAs($admin)
            ->patch(route('incidents.assignment.update', $incident), [
                'assigned_to_user_id' => $shipra->id,
            ])
            ->assertRedirect(route('incidents.show', $incident));

        $this->assertSame($shipra->id, $incident->fresh()->assigned_to_user_id);
    }

    public function test_assign_component_fragment_includes_workspace_fields_for_context(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin);

        $this->actingAs($admin)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'assign',
                'context' => WorkspaceContext::Dashboard->value,
            ]))
            ->assertOk()
            ->assertSee(route('incidents.workspace.assign', $incident), false)
            ->assertSee('name="workspace_context"', false)
            ->assertSee('value="dashboard"', false)
            ->assertSee('data-workspace-action-form="assign"', false);
    }
}
