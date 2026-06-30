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

class UniversalSearchTest extends TestCase
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

    public function test_guests_are_redirected_from_search(): void
    {
        $this->get(route('search.index'))
            ->assertRedirect(route('login'));
    }

    public function test_legacy_search_route_redirects_to_dashboard_with_query(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('search.index', ['q' => '9876543210']))
            ->assertRedirect(route('dashboard', ['q' => '9876543210']));
    }

    public function test_legacy_search_route_redirects_to_dashboard_without_query(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('search.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_dashboard_search_alias_returns_same_json_as_search_route(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [
            'order_id' => 'RD3434509',
        ]);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RD3434509']))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('incident_ids.0', $incident->id);

        $this->actingAs($agent)
            ->getJson(route('dashboard.search', ['q' => 'RD3434509']))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('incident_ids.0', $incident->id);
    }

    public function test_search_finds_service_case_by_mobile_number(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [
            'order_id' => 'RD3434509',
            'customer_phone' => '9876543210',
        ]);

        $response = $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => '9876543210']));

        $response->assertOk();
        $response->assertJsonPath('match_count', 1);
        $response->assertJsonPath('incident_ids.0', $incident->id);
        $response->assertSee('RD3434509', false);
    }

    public function test_search_finds_service_case_by_order_id(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [
            'order_id' => 'RD3434509',
        ]);

        $response = $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RD3434509']));

        $response->assertOk();
        $response->assertJsonPath('match_count', 1);
        $response->assertJsonPath('incident_ids.0', $incident->id);
    }

    public function test_search_finds_service_case_by_reference(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [], [
            'reference_no' => 'SC-01427',
        ]);

        $response = $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'SC01427']));

        $response->assertOk();
        $response->assertJsonPath('match_count', 1);
        $response->assertJsonPath('incident_ids.0', $incident->id);
        $response->assertSee('SC01427', false);
    }

    public function test_search_finds_service_cases_by_customer_name(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [
            'customer_name' => 'Danzo Shimura',
        ]);

        $response = $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'Danzo']));

        $response->assertOk();
        $response->assertJsonPath('match_count', 1);
        $response->assertJsonPath('incident_ids.0', $incident->id);
    }

    public function test_search_finds_service_case_by_serial_number(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [
            'serial_number' => 'SCN001',
        ]);

        $response = $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'SCN001']));

        $response->assertOk();
        $response->assertJsonPath('match_count', 1);
        $response->assertJsonPath('incident_ids.0', $incident->id);
    }

    public function test_search_finds_service_case_by_transaction_id_only_on_matching_tab(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [
            'order_id' => 'RD-TXN-SRCH',
            'transaction_id' => 'TXN-SEARCH-001',
        ]);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'TXN-SEARCH-001']))
            ->assertOk()
            ->assertJsonPath('match_count', 0);

        $response = $this->actingAs($agent)
            ->getJson(route('search.index', [
                'q' => 'TXN-SEARCH-001',
                'filter' => 'all',
            ]));

        $response->assertOk();
        $response->assertJsonPath('match_count', 1);
        $response->assertJsonPath('incident_ids.0', $incident->id);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->getJson(route('search.index', [
                'q' => 'TXN-SEARCH-001',
                'filter' => 'completed',
            ]))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('incident_ids.0', $incident->id);
    }

    public function test_search_respects_needs_attention_filter(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $missingSerialOrder = Order::query()->create([
            'order_id' => 'RD-NA-SRCH-MISSING',
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $presentSerialOrder = Order::query()->create([
            'order_id' => 'RD-NA-SRCH-PRESENT',
            'serial_number' => 'SN-NA-SRCH-PRESENT',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $missingIncident = Incident::query()->create([
            'order_id' => $missingSerialOrder->id,
            'reference_no' => 'SC-NA-SRCH-MISSING',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Missing serial search',
            'description' => 'Missing serial search.',
            'status' => IncidentStatus::Open->value,
            'created_by' => $admin->id,
            'assigned_to_user_id' => $admin->id,
        ]);

        $presentIncident = Incident::query()->create([
            'order_id' => $presentSerialOrder->id,
            'reference_no' => 'SC-NA-SRCH-PRESENT',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Present serial search',
            'description' => 'Present serial search.',
            'status' => IncidentStatus::Open->value,
            'created_by' => $admin->id,
            'assigned_to_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->getJson(route('search.index', [
                'q' => 'RD-NA-SRCH',
                'filter' => 'needs_attention',
            ]))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('incident_ids.0', $missingIncident->id);

        $this->actingAs($admin)
            ->getJson(route('search.index', [
                'q' => 'RD-NA-SRCH-PRESENT',
                'filter' => 'needs_attention',
            ]))
            ->assertOk()
            ->assertJsonPath('match_count', 0);

        $this->actingAs($admin)
            ->getJson(route('search.index', [
                'q' => 'RD-NA-SRCH-PRESENT',
                'filter' => 'all',
            ]))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('incident_ids.0', $presentIncident->id);
    }

    public function test_search_finds_service_case_by_normalized_reference_formats(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [], [
            'reference_no' => 'SC-00099',
        ]);

        foreach (['SC99', 'SC00099', 'SC-00099', '00099', '99'] as $query) {
            $this->actingAs($agent)
                ->getJson(route('search.index', ['q' => $query]))
                ->assertOk()
                ->assertJsonPath('match_count', 1)
                ->assertJsonPath('incident_ids.0', $incident->id);
        }
    }

    public function test_search_finds_service_case_by_customer_email(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [
            'order_id' => 'RD9990001',
            'customer_email' => 'support.customer@example.com',
        ]);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'support.customer']))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('incident_ids.0', $incident->id);
    }

    public function test_search_does_not_find_closed_service_cases_in_dashboard_tabs(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->createServiceCase($agent, [
            'order_id' => 'RD-CLOSED-001',
        ], [
            'status' => IncidentStatus::Closed,
        ]);

        foreach (['pending_admin', 'all'] as $filter) {
            $this->actingAs($agent)
                ->getJson(route('search.index', [
                    'q' => 'RD-CLOSED-001',
                    'filter' => $filter,
                ]))
                ->assertOk()
                ->assertJsonPath('match_count', 0);
        }
    }

    public function test_search_scopes_to_assignee_in_my_work_view(): void
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
            ->getJson(route('search.index', [
                'q' => '9000000001',
                'view' => 'my_work',
            ]));

        $response->assertOk();
        $response->assertJsonPath('match_count', 1);
        $response->assertJsonPath('incident_ids.0', $mine->id);
    }

    public function test_search_returns_empty_payload_for_blank_query(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => '']))
            ->assertOk()
            ->assertJsonPath('match_count', 0)
            ->assertJsonPath('rows', []);
    }

    public function test_search_returns_empty_payload_without_incident_permission(): void
    {
        $user = User::factory()->create();

        $this->createServiceCase($user, [
            'order_id' => 'RD3421021',
        ]);

        $this->actingAs($user)
            ->getJson(route('search.index', ['q' => 'RD3421021']))
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
