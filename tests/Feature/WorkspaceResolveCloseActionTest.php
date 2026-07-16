<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WorkspaceContext;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Remark;
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

    private function prepareAgentResolvableIncident(Incident $incident, User $agent): void
    {
        Remark::query()->create([
            'user_id' => $agent->id,
            'remarkable_type' => $incident->getMorphClass(),
            'remarkable_id' => $incident->id,
            'body' => 'Agent remark before resolve.',
        ]);

        $incident->order?->update(['transaction_id' => 'TXN-AGENT-1']);
    }

    public function test_resolve_action_returns_service_case_timeline_and_header_refresh_payload(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $incident = $this->createIncident($agent, ['status' => IncidentStatus::InProgress]);
        $this->prepareAgentResolvableIncident($incident, $agent);

        $response = $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.resolve', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'body' => 'Resolved after confirming replacement shipment.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'action')
            ->assertJsonPath('meta.context', WorkspaceContext::ServiceCase->value)
            ->assertJsonPath('ui.close_workspace_host', true)
            ->assertJsonPath('refresh.kpis', false)
            ->assertJsonFragment(['selector' => '#activity-timeline'])
            ->assertJsonFragment(['selector' => '.service-case-header']);

        $timelineHtml = collect($response->json('refresh.targets'))
            ->firstWhere('selector', '#activity-timeline')['html'] ?? '';
        $headerHtml = collect($response->json('refresh.targets'))
            ->firstWhere('selector', '.service-case-header')['html'] ?? '';

        $this->assertStringContainsString('Service case closed', $timelineHtml);
        $this->assertStringContainsString('Closed', $headerHtml);
        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
    }

    public function test_close_action_returns_service_case_timeline_and_header_refresh_payload(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $incident = $this->createIncident($agent, ['status' => IncidentStatus::InProgress]);
        $this->prepareAgentResolvableIncident($incident, $agent);

        $response = $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.close', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'body' => 'Closed after customer confirmation.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'action')
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
                'body' => 'Admin resolved from dashboard.',
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

        $this->assertStringNotContainsString(
            'data-workspace-trigger="resolve"',
            (string) $response->json('refresh.replace_row.html'),
        );
        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
    }

    public function test_close_action_returns_dashboard_refresh_payload_with_reopen_action_trigger(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin);

        $response = $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.close', $incident), [
                'workspace_context' => WorkspaceContext::Dashboard->value,
                'body' => 'Admin closed from dashboard.',
            ])
            ->assertOk()
            ->assertJsonPath('refresh.kpis', true);

        $rowHtml = (string) $response->json('refresh.replace_row.html');
        $this->assertStringNotContainsString('data-workspace-trigger="resolve"', $rowHtml);
        $this->assertStringNotContainsString('data-workspace-trigger="close"', $rowHtml);
        $this->assertStringNotContainsString('dashboard-actions-cell', $rowHtml);
        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
    }

    public function test_resolve_action_is_forbidden_for_unauthorized_user(): void
    {
        $unauthorized = User::factory()->create();
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $incident = $this->createIncident($agent);

        $this->actingAs($unauthorized)
            ->patchJson(route('incidents.workspace.resolve', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'body' => 'Should not apply.',
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
                'body' => 'Should not apply.',
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
                'body' => 'Should not apply.',
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
                'body' => 'Attempted resolve.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('action', 'action')
            ->assertJsonPath('ui.close_workspace_host', false)
            ->assertJsonPath('refresh.fragments.0.component', 'action');
    }

    public function test_close_action_returns_validation_response_with_fragment(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $incident = $this->createIncident($agent);

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.close', $incident), [
                'workspace_context' => 'invalid-context',
                'body' => 'Attempted close.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('action', 'action')
            ->assertJsonPath('ui.close_workspace_host', false)
            ->assertJsonPath('refresh.fragments.0.component', 'action');
    }

    public function test_legacy_status_update_routes_remain_unchanged(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin, ['status' => IncidentStatus::InProgress]);

        $this->actingAs($admin)
            ->patch(route('incidents.status.update', $incident), [
                'status' => 'resolved',
                'body' => 'Resolved via legacy route.',
            ])
            ->assertRedirect(route('incidents.show', $incident).'#activity-timeline')
            ->assertSessionHas('status', 'service-case-resolved');

        $this->actingAs($admin)
            ->patch(route('incidents.status.update', $incident), [
                'status' => 'closed',
                'body' => 'Closed via legacy route.',
            ])
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
            ->assertSee('data-workspace-action-form="resolve"', false)
            ->assertSee('name="body"', false)
            ->assertSee('data-mention-textarea', false);
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
            ->assertSee('data-workspace-action-form="close"', false)
            ->assertSee('name="body"', false);
    }

    public function test_agent_resolve_action_requires_remark_body_and_transaction_id(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $incident = $this->createIncident($agent);

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.resolve', $incident), [
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('ui.close_workspace_host', false)
            ->assertJsonStructure([
                'errors' => ['body'],
            ]);

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.resolve', $incident), [
                'workspace_context' => WorkspaceContext::Dashboard->value,
                'body' => 'Ready to resolve once transaction ID is assigned.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'errors' => ['transaction_id'],
            ]);

        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
    }

    public function test_agent_close_action_requires_remark_body_and_transaction_id(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $incident = $this->createIncident($agent);

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.close', $incident), [
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'errors' => ['body'],
            ]);

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.close', $incident), [
                'workspace_context' => WorkspaceContext::Dashboard->value,
                'body' => 'Ready to close once transaction ID is assigned.',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure([
                'errors' => ['transaction_id'],
            ]);

        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
    }

    public function test_admin_can_resolve_with_required_remark(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin);

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.resolve', $incident), [
                'workspace_context' => WorkspaceContext::Dashboard->value,
                'body' => 'Admin resolved with operational context.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
        $this->assertDatabaseHas('remarks', [
            'remarkable_type' => $incident->getMorphClass(),
            'remarkable_id' => $incident->id,
            'body' => 'Admin resolved with operational context.',
        ]);
    }

    public function test_resolve_action_persists_mention_relationship_without_notification(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $mentioned = User::factory()->create(['name' => 'Damini Patel', 'is_active' => true]);
        $incident = $this->createIncident($admin);

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.resolve', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'body' => 'Replacement shipped. @Damini Patel please confirm receipt.',
            ])
            ->assertOk();

        $remark = Remark::query()->where('remarkable_id', $incident->id)->latest('id')->first();
        $this->assertNotNull($remark);
        $this->assertDatabaseHas('remark_mentions', [
            'remark_id' => $remark->id,
            'user_id' => $mentioned->id,
        ]);
    }

    public function test_resolve_timeline_shows_remark_before_status_change(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin, ['status' => IncidentStatus::InProgress]);

        $response = $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.resolve', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'body' => 'Device reboot resolved the issue.',
            ])
            ->assertOk();

        $timelineHtml = collect($response->json('refresh.targets'))
            ->firstWhere('selector', '#activity-timeline')['html'] ?? '';

        $remarkPos = strpos($timelineHtml, 'Device reboot resolved the issue.');
        $closedPos = strpos($timelineHtml, 'Service case closed');

        $this->assertNotFalse($remarkPos);
        $this->assertNotFalse($closedPos);
        $this->assertLessThan($closedPos, $remarkPos);
    }

    public function test_dashboard_excludes_closed_cases_from_pending_admin_filter(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');

        $closedOrder = Order::query()->create([
            'order_id' => 'OID0001',
            'serial_number' => 'SN-CLOSED-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        Incident::query()->create([
            'order_id' => $closedOrder->id,
            'reference_no' => 'SC-CLOSED-1',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Closed pending admin case',
            'description' => 'Closed without transaction ID.',
            'status' => IncidentStatus::Closed,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('dashboard', ['filter' => 'pending_admin']))
            ->assertOk()
            ->assertDontSee('OID0001')
            ->assertDontSee('SC-CLOSED-1');
    }

    public function test_open_cases_kpi_excludes_closed_incidents(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');

        $order = Order::query()->create([
            'order_id' => 'OID-KPI-1',
            'serial_number' => 'SN-KPI-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-OPEN-1',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Open case',
            'description' => 'Open case for KPI test.',
            'status' => IncidentStatus::Open,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-CLOSED-KPI',
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Closed case',
            'description' => 'Closed case for KPI test.',
            'status' => IncidentStatus::Closed,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $stats = app(\App\Services\DashboardService::class)->statsFor($admin);

        $this->assertSame(1, $stats['open_incidents']);
    }

    public function test_dashboard_rows_open_customer360_without_actions_column(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $this->createIncident($agent, ['assigned_to_user_id' => $agent->id]);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('dashboard-case-row--clickable', false)
            ->assertDontSee('data-c360-open-more-menu', false)
            ->assertDontSee('data-workspace-trigger="resolve"', false)
            ->assertDontSee('data-workspace-trigger="close"', false);
    }

    public function test_dashboard_rows_have_no_actions_column_for_closed_cases(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $this->createIncident($agent, [
            'status' => IncidentStatus::Closed,
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('data-c360-open-more-menu', false)
            ->assertDontSee('data-workspace-trigger="resolve"', false)
            ->assertDontSee('data-workspace-trigger="close"', false);
    }
}
