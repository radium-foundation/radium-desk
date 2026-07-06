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

class WorkspaceDashboardAssignTest extends TestCase
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

    private function createOpenIncident(User $creator): Incident
    {
        $order = Order::query()->create([
            'order_id' => 'ORD-DASH-WS-1',
            'serial_number' => 'SN-DASH-WS-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Dashboard workspace assign test',
            'description' => 'Dashboard workspace assign test.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }

    public function test_dashboard_declares_workspace_context_and_action_trigger_for_admin(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createOpenIncident($admin);
        $incident->update(['assigned_to_user_id' => $admin->id]);

        $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'action_required']))
            ->assertOk()
            ->assertSee('data-workspace-context="dashboard"', false)
            ->assertSee('id="workspace-context-slugs"', false)
            ->assertSee('data-workspace-trigger="action"', false)
            ->assertSee('data-workspace-incident-id="'.$incident->id.'"', false)
            ->assertSee('dashboard-actions-cell', false);
    }

    public function test_dashboard_shows_action_for_agents(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createOpenIncident($agent);
        $incident->update(['assigned_to_user_id' => $agent->id]);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-workspace-trigger="action"', false)
            ->assertSee('data-workspace-trigger="remark"', false)
            ->assertSee('dashboard-actions-cell', false);
    }

    public function test_dashboard_assign_action_returns_row_and_kpi_refresh_payload(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $shipra = $this->createAdminUser('shipra@example.com', 'Shipra Kumari');
        $incident = $this->createOpenIncident($admin);

        $response = $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.assign', $incident), [
                'assigned_to_user_id' => $shipra->id,
                'workspace_context' => WorkspaceContext::Dashboard->value,
                'body' => 'Dashboard assign with remark.',
            ])
            ->assertOk()
            ->assertJsonPath('meta.context', WorkspaceContext::Dashboard->value)
            ->assertJsonPath('ui.close_workspace_host', true)
            ->assertJsonPath('refresh.kpis', true)
            ->assertJsonStructure([
                'refresh' => [
                    'replace_row' => ['incident_id', 'html', 'strategy'],
                    'kpis_html' => ['kpi_strip_html'],
                ],
            ]);

        $this->assertStringContainsString(
            'data-workspace-trigger="action"',
            (string) $response->json('refresh.replace_row.html'),
        );
    }

    public function test_live_dashboard_rows_include_action_trigger_for_admin(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createOpenIncident($admin);
        $incident->update(['assigned_to_user_id' => $admin->id]);

        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.live', ['queue' => 'action_required']))
            ->assertOk();

        $this->assertStringContainsString(
            'data-workspace-trigger="action"',
            (string) $response->json('rows.0.html'),
        );
    }
}
