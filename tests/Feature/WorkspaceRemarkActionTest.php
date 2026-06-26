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
            'title' => $overrides['title'] ?? 'Workspace remark action test',
            'description' => $overrides['description'] ?? 'Workspace remark action test description.',
            'status' => $overrides['status'] ?? IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            ...$overrides,
        ]);
    }

    public function test_remark_action_returns_service_case_timeline_refresh_payload(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $incident = $this->createIncident($agent);

        $response = $this->actingAs($agent)
            ->postJson(route('incidents.workspace.remark', $incident), [
                'body' => 'Customer confirmed @Damini will call back.',
                'workspace_context' => WorkspaceContext::ServiceCase->value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'remark')
            ->assertJsonPath('incident_id', $incident->id)
            ->assertJsonPath('meta.context', WorkspaceContext::ServiceCase->value)
            ->assertJsonPath('ui.close_workspace_host', true)
            ->assertJsonPath('refresh.kpis', false)
            ->assertJsonPath('refresh.replace_row', null)
            ->assertJsonFragment(['selector' => '#activity-timeline']);

        $timelineHtml = collect($response->json('refresh.targets'))
            ->firstWhere('selector', '#activity-timeline')['html'] ?? '';

        $this->assertStringContainsString('Customer confirmed', $timelineHtml);
        $this->assertStringContainsString('remark-mention', $timelineHtml);
        $this->assertStringContainsString('@Damini', $timelineHtml);
        $this->assertStringNotContainsString('.service-case-header', $timelineHtml);

        $this->assertDatabaseHas('remarks', [
            'remarkable_type' => $incident->getMorphClass(),
            'remarkable_id' => $incident->id,
            'body' => 'Customer confirmed @Damini will call back.',
        ]);
    }

    public function test_remark_action_returns_dashboard_refresh_payload(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin);

        $response = $this->actingAs($admin)
            ->postJson(route('incidents.workspace.remark', $incident), [
                'body' => 'Dashboard remark from workspace.',
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.context', WorkspaceContext::Dashboard->value)
            ->assertJsonPath('ui.close_workspace_host', true)
            ->assertJsonPath('refresh.kpis', true)
            ->assertJsonStructure([
                'refresh' => [
                    'kpis_html' => ['action_stats_html', 'sla_cards_html'],
                    'replace_row' => ['incident_id', 'html', 'strategy'],
                ],
            ])
            ->assertJsonPath('refresh.replace_row.incident_id', $incident->id);

        $this->assertStringContainsString(
            'data-workspace-trigger="remark"',
            (string) $response->json('refresh.replace_row.html'),
        );
    }

    public function test_remark_action_is_forbidden_for_unauthorized_user(): void
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

    public function test_remark_action_returns_validation_response_with_fragment_and_preserved_text(): void
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

        $fragmentHtml = (string) $response->json('refresh.fragments.0.html');
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

    public function test_remark_component_fragment_includes_workspace_fields_for_context(): void
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
}
