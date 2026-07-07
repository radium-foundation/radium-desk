<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardPersonalizationService;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardGlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @param  array<string, mixed>  $orderAttributes
     * @param  array<string, mixed>  $incidentAttributes
     */
    private function createServiceCase(User $user, array $orderAttributes = [], array $incidentAttributes = []): Incident
    {
        $order = Order::query()->create([
            'order_id' => $orderAttributes['order_id'] ?? 'RD-'.uniqid(),
            'serial_number' => $orderAttributes['serial_number'] ?? 'SN-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => $orderAttributes['transaction_id'] ?? null,
            'customer_name' => $orderAttributes['customer_name'] ?? null,
            'customer_email' => $orderAttributes['customer_email'] ?? null,
            'customer_phone' => $orderAttributes['customer_phone'] ?? null,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $incidentAttributes['reference_no'] ?? app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => $incidentAttributes['title'] ?? 'Dashboard search case',
            'description' => $incidentAttributes['description'] ?? 'Dashboard search case.',
            'status' => $incidentAttributes['status'] ?? IncidentStatus::Open,
            'high_priority' => $incidentAttributes['high_priority'] ?? false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'assigned_to_user_id' => $incidentAttributes['assigned_to_user_id'] ?? $user->id,
            ...$incidentAttributes,
        ]);
    }

    public function test_search_rows_returns_dashboard_row_html_for_incident_ids(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [
            'order_id' => 'RD-SEARCH-ROW-001',
        ]);

        $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.search-rows', ['ids' => [$incident->id]]))
            ->assertOk()
            ->assertJsonPath('service_cases_empty', false)
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.incident_id', $incident->id)
            ->assertJsonPath('incident_ids.0', $incident->id)
            ->assertSee('RD-SEARCH-ROW-001', false);
    }

    public function test_search_rows_preserves_requested_order(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $first = $this->createServiceCase($agent, ['order_id' => 'RD-ORDER-A']);
        $second = $this->createServiceCase($agent, ['order_id' => 'RD-ORDER-B']);

        $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.search-rows', ['ids' => [$second->id, $first->id]]))
            ->assertOk()
            ->assertJsonPath('incident_ids.0', $second->id)
            ->assertJsonPath('incident_ids.1', $first->id);
    }

    public function test_search_rows_includes_cases_outside_assignee_scope(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $otherAgent = User::factory()->create();
        $otherAgent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $otherCase = $this->createServiceCase($otherAgent, [
            'order_id' => 'RD-OTHER-AGENT-001',
        ], [
            'assigned_to_user_id' => $otherAgent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('dashboard', ['queue' => 'my_work']))
            ->assertOk()
            ->assertDontSee('RD-OTHER-AGENT-001');

        $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.search-rows', ['ids' => [$otherCase->id]]))
            ->assertOk()
            ->assertJsonPath('service_cases_empty', false)
            ->assertJsonPath('incident_ids.0', $otherCase->id)
            ->assertSee('RD-OTHER-AGENT-001', false);
    }

    public function test_global_search_returns_cases_outside_assignee_restrictions(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $otherAgent = User::factory()->create();
        $otherAgent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $otherCase = $this->createServiceCase($otherAgent, [
            'order_id' => 'RD-GLOBAL-ASSIGNEE-001',
        ], [
            'assigned_to_user_id' => $otherAgent->id,
        ]);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RD-GLOBAL-ASSIGNEE-001']))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('incident_ids.0', $otherCase->id);
    }

    public function test_dashboard_includes_search_rows_url_data_attribute(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('dashboard.service-cases.search-rows'), false)
            ->assertSee('data-dashboard-search-rows-url', false);
    }

    public function test_dashboard_live_refresh_is_unchanged_when_no_search_is_active(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [
            'order_id' => 'RD-LIVE-UNCHANGED-001',
        ]);

        $this->actingAs($agent)
            ->getJson(route('dashboard.live', ['filter' => 'all']))
            ->assertOk()
            ->assertJsonPath('service_cases_empty', false)
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('incident_ids.0', $incident->id)
            ->assertSee('RD-LIVE-UNCHANGED-001', false);
    }

    public function test_search_rows_returns_empty_payload_for_no_ids(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.search-rows'))
            ->assertOk()
            ->assertJsonPath('service_cases_empty', true)
            ->assertJsonPath('rows', [])
            ->assertJsonPath('incident_ids', []);
    }

    public function test_search_rows_includes_closed_case(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $closedCase = $this->createServiceCase($agent, [
            'order_id' => 'RD-CLOSED-SEARCH-001',
        ], [
            'status' => IncidentStatus::Closed,
        ]);

        $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.search-rows', ['ids' => [$closedCase->id]]))
            ->assertOk()
            ->assertJsonPath('service_cases_empty', false)
            ->assertJsonPath('incident_ids.0', $closedCase->id)
            ->assertSee('RD-CLOSED-SEARCH-001', false);
    }

    public function test_global_search_finds_closed_case_by_order_id(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $closedCase = $this->createServiceCase($agent, [
            'order_id' => 'RD3437143',
        ], [
            'status' => IncidentStatus::Closed,
        ]);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RD3437143']))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('incident_ids.0', $closedCase->id);
    }

    public function test_dashboard_search_finds_case_outside_assignee_queue(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $otherAgent = User::factory()->create();
        $otherAgent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $otherCase = $this->createServiceCase($otherAgent, [
            'order_id' => 'RD3437143',
        ], [
            'assigned_to_user_id' => $otherAgent->id,
            'status' => IncidentStatus::Closed,
        ]);

        $this->actingAs($agent)
            ->get(route('dashboard', ['queue' => 'my_work', 'q' => 'RD3437143']))
            ->assertOk()
            ->assertSee('data-dashboard-search-rows-url', false)
            ->assertDontSee('RD3437143');

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RD3437143']))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('incident_ids.0', $otherCase->id);

        $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.search-rows', ['ids' => [$otherCase->id]]))
            ->assertOk()
            ->assertJsonPath('service_cases_empty', false)
            ->assertSee('RD3437143', false);
    }

    public function test_dashboard_redirect_preserves_q_param(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get('/dashboard?view=hardware_orders&q=RD3437143')
            ->assertRedirect(route('dashboard', [
                'queue' => DashboardPersonalizationService::QUEUE_HARDWARE,
                'q' => 'RD3437143',
            ]));
    }
}
