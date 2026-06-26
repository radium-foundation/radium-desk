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

class WorkspaceResolveCloseActionTest extends TestCase
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

    private function createAgentUser(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    private function createIncident(User $creator, array $overrides = []): Incident
    {
        $order = Order::query()->create([
            'order_id' => $overrides['order_id'] ?? 'ORD-WSRC-1',
            'serial_number' => 'SN-WSRC-1',
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
            'title' => $overrides['title'] ?? 'Workspace resolve close test',
            'description' => $overrides['description'] ?? 'Workspace resolve close test description.',
            'status' => $overrides['status'] ?? IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            ...$overrides,
        ]);
    }

    public function test_resolve_action_returns_service_case_timeline_and_header_refresh_payload(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $incident = $this->createIncident($agent, ['status' => IncidentStatus::InProgress]);

        $response = $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.resolve', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'resolve')
            ->assertJsonPath('meta.context', WorkspaceContext::ServiceCase->value)
            ->assertJsonPath('ui.close_workspace_host', true)
            ->assertJsonPath('refresh.kpis', false)
            ->assertJsonFragment(['selector' => '#activity-timeline'])
            ->assertJsonFragment(['selector' => '.service-case-header']);

        $timelineHtml = collect($response->json('refresh.targets'))
            ->firstWhere('selector', '#activity-timeline')['html'] ?? '';
        $headerHtml = collect($response->json('refresh.targets'))
            ->firstWhere('selector', '.service-case-header')['html'] ?? '';

        $this->assertStringContainsString('Status: In Progress', $timelineHtml);
        $this->assertStringContainsString('Resolved', $timelineHtml);
        $this->assertStringContainsString('Resolved', $headerHtml);
        $this->assertSame(IncidentStatus::Resolved, $incident->fresh()->status);
    }

    public function test_close_action_returns_service_case_timeline_and_header_refresh_payload(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $incident = $this->createIncident($agent, ['status' => IncidentStatus::Resolved]);

        $response = $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.close', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'close')
            ->assertJsonFragment(['selector' => '#activity-timeline'])
            ->assertJsonFragment(['selector' => '.service-case-header']);

        $headerHtml = collect($response->json('refresh.targets'))
            ->firstWhere('selector', '.service-case-header')['html'] ?? '';

        $this->assertStringContainsString('Closed', $headerHtml);
        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
    }

    public function test_resolve_action_returns_dashboard_refresh_payload(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin);

        $response = $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.resolve', $incident), [
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertOk()
            ->assertJsonPath('meta.context', WorkspaceContext::Dashboard->value)
            ->assertJsonPath('refresh.kpis', true)
            ->assertJsonStructure([
                'refresh' => [
                    'kpis_html' => ['kpi_strip_html'],
                    'replace_row' => ['incident_id', 'html', 'strategy'],
                ],
            ]);

        $this->assertStringContainsString(
            'data-workspace-trigger="close"',
            (string) $response->json('refresh.replace_row.html'),
        );
        $this->assertStringNotContainsString(
            'data-workspace-trigger="resolve"',
            (string) $response->json('refresh.replace_row.html'),
        );
    }

    public function test_close_action_returns_dashboard_refresh_payload_without_resolve_or_close_triggers(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin);

        $response = $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.close', $incident), [
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertOk()
            ->assertJsonPath('refresh.kpis', true);

        $rowHtml = (string) $response->json('refresh.replace_row.html');
        $this->assertStringNotContainsString('data-workspace-trigger="resolve"', $rowHtml);
        $this->assertStringNotContainsString('data-workspace-trigger="close"', $rowHtml);
    }

    public function test_resolve_action_is_forbidden_for_unauthorized_user(): void
    {
        $unauthorized = User::factory()->create();
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $incident = $this->createIncident($agent);

        $this->actingAs($unauthorized)
            ->patchJson(route('incidents.workspace.resolve', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
            ])
            ->assertForbidden();

        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
    }

    public function test_close_action_is_forbidden_for_closed_service_case(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin, ['status' => IncidentStatus::Closed]);

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.close', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
            ])
            ->assertForbidden();

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
    }

    public function test_resolve_action_is_forbidden_for_closed_service_case(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin, ['status' => IncidentStatus::Closed]);

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.resolve', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
            ])
            ->assertForbidden();
    }

    public function test_resolve_action_returns_validation_response_with_fragment(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $incident = $this->createIncident($agent);

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.resolve', $incident), [
                'workspace_context' => 'invalid-context',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('action', 'resolve')
            ->assertJsonPath('ui.close_workspace_host', false)
            ->assertJsonPath('refresh.fragments.0.component', 'resolve');
    }

    public function test_close_action_returns_validation_response_with_fragment(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $incident = $this->createIncident($agent);

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.close', $incident), [
                'workspace_context' => 'invalid-context',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('action', 'close')
            ->assertJsonPath('ui.close_workspace_host', false)
            ->assertJsonPath('refresh.fragments.0.component', 'close');
    }

    public function test_legacy_status_update_routes_remain_unchanged(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin, ['status' => IncidentStatus::InProgress]);

        $this->actingAs($admin)
            ->patch(route('incidents.status.update', $incident), ['status' => 'resolved'])
            ->assertRedirect(route('incidents.show', $incident).'#activity-timeline')
            ->assertSessionHas('status', 'service-case-resolved');

        $this->actingAs($admin)
            ->patch(route('incidents.status.update', $incident), ['status' => 'closed'])
            ->assertSessionHas('status', 'service-case-closed');

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
    }

    public function test_resolve_component_fragment_includes_workspace_fields_for_context(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $incident = $this->createIncident($agent);

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'resolve',
                'context' => WorkspaceContext::Dashboard->value,
            ]))
            ->assertOk()
            ->assertSee(route('incidents.workspace.resolve', $incident), false)
            ->assertSee('name="workspace_context"', false)
            ->assertSee('value="dashboard"', false)
            ->assertSee('data-workspace-action-form="resolve"', false);
    }

    public function test_close_component_fragment_includes_workspace_fields_for_context(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $incident = $this->createIncident($agent);

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'close',
                'context' => WorkspaceContext::ServiceCase->value,
            ]))
            ->assertOk()
            ->assertSee(route('incidents.workspace.close', $incident), false)
            ->assertSee('value="service_case"', false)
            ->assertSee('data-workspace-action-form="close"', false);
    }

    public function test_dashboard_shows_resolve_and_close_triggers_for_updatable_cases(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $this->createIncident($agent);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-workspace-trigger="resolve"', false)
            ->assertSee('data-workspace-trigger="close"', false);
    }

    public function test_dashboard_hides_resolve_and_close_for_closed_cases(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $this->createIncident($agent, ['status' => IncidentStatus::Closed]);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('data-workspace-trigger="resolve"', false)
            ->assertDontSee('data-workspace-trigger="close"', false);
    }
}
