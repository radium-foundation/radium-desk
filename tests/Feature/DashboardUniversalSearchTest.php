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

class DashboardUniversalSearchTest extends TestCase
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
            'title' => 'Search test case',
            'description' => 'Search test case.',
            'status' => $incidentAttributes['status'] ?? IncidentStatus::Open,
            'created_by' => $user->id,
            'assigned_to_user_id' => $incidentAttributes['assigned_to_user_id'] ?? $user->id,
        ]);
    }

    public function test_guests_are_redirected_from_dashboard_search(): void
    {
        $this->get(route('dashboard.search', ['q' => '9876543210']))
            ->assertRedirect(route('login'));
    }

    public function test_dashboard_search_finds_service_case_by_mobile_number(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [
            'order_id' => 'RD3434509',
            'customer_phone' => '9876543210',
        ]);

        $response = $this->actingAs($agent)
            ->getJson(route('dashboard.search', ['q' => '9876543210']));

        $response->assertOk();
        $response->assertJsonPath('match_count', 1);
        $response->assertJsonPath('incident_ids.0', $incident->id);
        $response->assertSee('RD3434509', false);
    }

    public function test_dashboard_search_finds_service_case_by_order_id(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [
            'order_id' => 'RD3434509',
        ]);

        $response = $this->actingAs($agent)
            ->getJson(route('dashboard.search', ['q' => 'RD3434509']));

        $response->assertOk();
        $response->assertJsonPath('match_count', 1);
        $response->assertJsonPath('incident_ids.0', $incident->id);
    }

    public function test_dashboard_search_finds_service_case_by_reference(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [], [
            'reference_no' => 'SC-01427',
        ]);

        $response = $this->actingAs($agent)
            ->getJson(route('dashboard.search', ['q' => 'SC01427']));

        $response->assertOk();
        $response->assertJsonPath('match_count', 1);
        $response->assertJsonPath('incident_ids.0', $incident->id);
        $response->assertSee('SC01427', false);
    }

    public function test_dashboard_search_finds_service_cases_by_customer_name(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [
            'customer_name' => 'Danzo Shimura',
        ]);

        $response = $this->actingAs($agent)
            ->getJson(route('dashboard.search', ['q' => 'Danzo']));

        $response->assertOk();
        $response->assertJsonPath('match_count', 1);
        $response->assertJsonPath('incident_ids.0', $incident->id);
    }

    public function test_dashboard_search_finds_service_case_by_serial_number(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [
            'serial_number' => 'SCN001',
        ]);

        $response = $this->actingAs($agent)
            ->getJson(route('dashboard.search', ['q' => 'SCN001']));

        $response->assertOk();
        $response->assertJsonPath('match_count', 1);
        $response->assertJsonPath('incident_ids.0', $incident->id);
    }

    public function test_dashboard_search_finds_closed_service_cases(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [
            'order_id' => 'RD-CLOSED-001',
        ], [
            'status' => IncidentStatus::Closed,
        ]);

        $response = $this->actingAs($agent)
            ->getJson(route('dashboard.search', ['q' => 'RD-CLOSED-001']));

        $response->assertOk();
        $response->assertJsonPath('match_count', 1);
        $response->assertJsonPath('incident_ids.0', $incident->id);
    }

    public function test_dashboard_search_scopes_to_assignee_in_my_work_view(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $otherAgent = User::factory()->create();
        $otherAgent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $mine = $this->createServiceCase($agent, [
            'customer_phone' => '9000000001',
        ], [
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->createServiceCase($otherAgent, [
            'customer_phone' => '9000000002',
        ], [
            'assigned_to_user_id' => $otherAgent->id,
        ]);

        $response = $this->actingAs($agent)
            ->getJson(route('dashboard.search', [
                'q' => '9000000001',
                'view' => 'my_work',
            ]));

        $response->assertOk();
        $response->assertJsonPath('match_count', 1);
        $response->assertJsonPath('incident_ids.0', $mine->id);
    }

    public function test_dashboard_search_returns_empty_payload_for_blank_query(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->getJson(route('dashboard.search', ['q' => '']))
            ->assertOk()
            ->assertJsonPath('match_count', 0)
            ->assertJsonPath('rows', []);
    }

    public function test_dashboard_page_includes_search_url_data_attribute(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('dashboard.search'), false);
    }
}
