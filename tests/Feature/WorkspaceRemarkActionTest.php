<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WorkspaceActionType;
use App\Enums\WorkspaceContext;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Remark;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceRemarkActionTest extends TestCase
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
            'order_id' => $overrides['order_id'] ?? 'ORD-WSR-1',
            'serial_number' => 'SN-WSR-1',
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
            'title' => $overrides['title'] ?? 'Workspace note action test',
            'description' => $overrides['description'] ?? 'Workspace note action test description.',
            'status' => $overrides['status'] ?? IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            ...$overrides,
        ]);
    }

    public function test_note_action_returns_service_case_timeline_refresh_payload(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        User::factory()->create(['name' => 'Damini', 'is_active' => true]);
        $incident = $this->createIncident($agent);
        $originalStatus = $incident->status;
        $originalAssignee = $incident->assigned_to_user_id;

        $response = $this->actingAs($agent)
            ->postJson(route('incidents.workspace.remark', $incident), [
                'body' => 'Customer confirmed @Damini will call back.',
                'workspace_context' => WorkspaceContext::ServiceCase->value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'remark')
            ->assertJsonPath('toast.message', 'Note saved.')
            ->assertJsonPath('incident_id', $incident->id)
            ->assertJsonPath('meta.context', WorkspaceContext::ServiceCase->value)
            ->assertJsonPath('ui.close_workspace_host', true)
            ->assertJsonPath('refresh.kpis', false)
            ->assertJsonPath('refresh.replace_row', null)
            ->assertJsonFragment(['selector' => '#activity-timeline']);

        $timelineHtml = collect($response->json('refresh.targets'))
            ->firstWhere('selector', '#activity-timeline')['html'] ?? '';

        $this->assertStringContainsString('Internal Note', $timelineHtml);
        $this->assertStringContainsString('Customer confirmed', $timelineHtml);
        $this->assertStringContainsString('remark-mention', $timelineHtml);
        $this->assertStringContainsString('@Damini', $timelineHtml);
        $this->assertStringContainsString('By:', $timelineHtml);
        $this->assertStringContainsString('Mentioned: Damini', $timelineHtml);
        $this->assertStringNotContainsString('.service-case-header', $timelineHtml);

        $freshIncident = $incident->fresh();
        $this->assertSame($originalStatus, $freshIncident->status);
        $this->assertSame($originalAssignee, $freshIncident->assigned_to_user_id);

        $this->assertDatabaseHas('remarks', [
            'remarkable_type' => $incident->getMorphClass(),
            'remarkable_id' => $incident->id,
            'body' => 'Customer confirmed @Damini will call back.',
        ]);

        $remark = Remark::query()->where('remarkable_id', $incident->id)->first();
        $this->assertNotNull($remark);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $remark->getMorphClass(),
            'auditable_id' => $remark->id,
            'event' => 'created',
        ]);
    }

    public function test_note_component_fragment_renders_add_note_dialog(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $incident = $this->createIncident($agent);

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'remark',
                'context' => WorkspaceContext::ServiceCase->value,
            ]))
            ->assertOk()
            ->assertSee('Add Note', false)
            ->assertSee('Save Note', false)
            ->assertDontSee('Notify Customer', false)
            ->assertSee(route('incidents.workspace.remark', $incident), false)
            ->assertSee('data-workspace-action-form="remark"', false)
            ->assertSee('data-mention-textarea', false);
    }

    public function test_note_action_returns_dashboard_refresh_payload(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin);

        $response = $this->actingAs($admin)
            ->postJson(route('incidents.workspace.remark', $incident), [
                'body' => 'Dashboard note from workspace.',
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.context', WorkspaceContext::Dashboard->value)
            ->assertJsonPath('ui.close_workspace_host', true)
            ->assertJsonPath('refresh.kpis', true)
            ->assertJsonStructure([
                'refresh' => [
                    'kpis_html' => ['kpi_strip_html'],
                    'replace_row' => ['incident_id', 'html', 'strategy'],
                ],
            ])
            ->assertJsonPath('refresh.replace_row.incident_id', $incident->id);

        $this->assertStringContainsString(
            'data-workspace-trigger="remark"',
            (string) $response->json('refresh.replace_row.html'),
        );
    }

    public function test_note_action_is_forbidden_for_unauthorized_user(): void
    {
        $unauthorized = User::factory()->create();

        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $incident = $this->createIncident($agent);

        $this->actingAs($unauthorized)
            ->postJson(route('incidents.workspace.remark', $incident), [
                'body' => 'Should not be saved.',
                'workspace_context' => WorkspaceContext::ServiceCase->value,
            ])
            ->assertForbidden();

        $this->assertSame(0, Remark::query()->count());
    }

    public function test_note_action_returns_validation_response_with_fragment_and_preserved_text(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $incident = $this->createIncident($agent);

        $response = $this->actingAs($agent)
            ->postJson(route('incidents.workspace.remark', $incident), [
                'body' => 'no',
                'workspace_context' => WorkspaceContext::ServiceCase->value,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('action', 'remark')
            ->assertJsonPath('ui.close_workspace_host', false)
            ->assertJsonStructure([
                'errors' => ['body'],
                'refresh' => [
                    'fragments' => [
                        ['component', 'target', 'html', 'strategy'],
                    ],
                ],
            ])
            ->assertJsonPath('refresh.fragments.0.component', 'remark');

        $this->assertStringNotContainsString(
            'Please correct the highlighted fields.',
            (string) $response->json('toast.message'),
        );

        $fragmentHtml = (string) $response->json('refresh.fragments.0.html');
        $this->assertStringContainsString('Add Note', $fragmentHtml);
        $this->assertStringContainsString('name="body"', $fragmentHtml);
        $this->assertStringContainsString('>no</textarea>', $fragmentHtml);
        $this->assertSame(0, Remark::query()->count());
    }

    public function test_legacy_remark_route_still_works(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $incident = $this->createIncident($agent);

        $this->actingAs($agent)
            ->from(route('incidents.show', $incident))
            ->post(route('remarks.store'), [
                'remarkable_type' => Incident::class,
                'remarkable_id' => $incident->id,
                'body' => 'Legacy remark still works.',
            ])
            ->assertRedirect(route('incidents.show', $incident).'#activity-timeline');

        $this->assertDatabaseHas('remarks', [
            'remarkable_id' => $incident->id,
            'body' => 'Legacy remark still works.',
        ]);
    }

    public function test_note_component_fragment_includes_workspace_fields_for_context(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $incident = $this->createIncident($agent);

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'remark',
                'context' => WorkspaceContext::Dashboard->value,
            ]))
            ->assertOk()
            ->assertSee(route('incidents.workspace.remark', $incident), false)
            ->assertSee('name="workspace_context"', false)
            ->assertSee('value="dashboard"', false)
            ->assertSee('data-workspace-action-form="remark"', false)
            ->assertSee('data-mention-textarea', false);
    }

    public function test_action_workflow_is_unaffected_by_note_changes(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $shipra = $this->createAdminUser('shipra@example.com', 'Shipra Kumari');
        $incident = $this->createIncident($admin);

        $this->actingAs($admin)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'action',
                'context' => WorkspaceContext::ServiceCase->value,
            ]))
            ->assertOk()
            ->assertSee('Customer Action', false)
            ->assertSee('data-workspace-action-card="assign"', false);

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Assign->value,
                'assigned_to_user_id' => $shipra->id,
                'body' => 'Assigning after note UX update.',
            ])
            ->assertOk()
            ->assertJsonPath('action', 'action');

        $this->assertSame($shipra->id, $incident->fresh()->assigned_to_user_id);
    }
}
