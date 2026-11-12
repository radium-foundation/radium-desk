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
        $this->withoutVite();
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

    public function test_dashboard_declares_workspace_context_for_admin(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createOpenIncident($admin);
        $incident->update(['assigned_to_user_id' => $admin->id]);

        $this->actingAs($admin)
            ->get(route('dashboard', $this->dashboardQuery(['queue' => 'action_required'])))
            ->assertOk()
            ->assertSee('data-workspace-context="dashboard"', false)
            ->assertSee('id="workspace-context-slugs"', false)
            ->assertDontSee('dashboard-actions-cell', false);

        $rowHtml = (string) $this->actingAs($admin)
            ->getJson(route('dashboard.service-cases.search-rows', ['ids' => [$incident->id]]))
            ->assertOk()
            ->json('rows.0.html');

        $this->assertStringContainsString('data-incident-id="'.$incident->id.'"', $rowHtml);
        $this->assertStringNotContainsString('dashboard-actions-cell', $rowHtml);
    }

    public function test_dashboard_row_opens_customer360_via_click(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createOpenIncident($agent);
        $incident->update(['assigned_to_user_id' => $agent->id]);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk();

        $rowHtml = (string) $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.search-rows', ['ids' => [$incident->id]]))
            ->assertOk()
            ->json('rows.0.html');

        $this->assertStringContainsString('dashboard-case-row--clickable', $rowHtml);
        $this->assertStringContainsString('data-incident-id="'.$incident->id.'"', $rowHtml);
        $this->assertStringNotContainsString('dashboard-actions-cell', $rowHtml);
        $this->assertStringNotContainsString('data-c360-open-more-menu', $rowHtml);
    }

    public function test_dashboard_assign_action_returns_row_and_kpi_refresh_payload(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $shipra = $this->createAdminUser('shipra@example.com', 'Shipra Kumari');
        $incident = $this->createOpenIncident($admin);
        $incident->update([
            'assigned_to_user_id' => $admin->id,
        ]);
        $incident->order->update([
            'order_id' => 'RD-WS-DASH-ASSIGN-1',
            'serial_number' => 'SN-DASH-WS-RD-1',
            'cashfree_payment_id' => 'cf_dash_ws_assign',
        ]);

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
                    'kpis_html' => ['kpi_strip_html'],
                ],
            ]);

        $replaceRow = $response->json('refresh.replace_row');
        if (is_array($replaceRow)) {
            $this->assertStringNotContainsString(
                'dashboard-actions-cell',
                (string) ($replaceRow['html'] ?? ''),
            );
        }

        $rowHtml = (string) $this->actingAs($admin)
            ->getJson(route('dashboard.service-cases.search-rows', ['ids' => [$incident->id]]))
            ->assertOk()
            ->json('rows.0.html');

        $this->assertStringNotContainsString('dashboard-actions-cell', $rowHtml);
        $this->assertStringContainsString('data-incident-id="'.$incident->id.'"', $rowHtml);
    }

    public function test_live_dashboard_rows_exclude_actions_column_for_admin(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createOpenIncident($admin);
        $incident->update(['assigned_to_user_id' => $admin->id]);

        $rowHtml = (string) $this->actingAs($admin)
            ->getJson(route('dashboard.service-cases.search-rows', ['ids' => [$incident->id]]))
            ->assertOk()
            ->json('rows.0.html');

        $this->assertStringNotContainsString('dashboard-actions-cell', $rowHtml);
        $this->assertStringContainsString('data-incident-id="'.$incident->id.'"', $rowHtml);
    }
}
