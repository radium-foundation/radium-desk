<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Remark;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\UniversalSearchService;
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

    public function test_legacy_search_route_redirects_to_dashboard(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('search.index', ['q' => '9876543210']))
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
            ->assertJsonPath('incident_ids.0', $incident->id)
            ->assertJsonPath('results.0.service_case', $incident->display_reference)
            ->assertJsonPath('results.0.order_id', 'RD3434509');

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
        $response->assertJsonPath('results.0.phone', '9876543210');
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
        $response->assertJsonPath('results.0.service_case', 'SC01427');
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

    public function test_search_finds_service_case_by_transaction_id(): void
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
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('incident_ids.0', $incident->id);
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

    public function test_search_finds_service_case_by_note_body(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [
            'order_id' => 'RD-NOTE-SEARCH-001',
        ]);

        Remark::query()->create([
            'user_id' => $agent->id,
            'remarkable_type' => $incident->getMorphClass(),
            'remarkable_id' => $incident->id,
            'body' => 'Customer requested replacement keypad cover.',
        ]);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'keypad cover']))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('incident_ids.0', $incident->id);
    }

    public function test_search_does_not_match_note_metadata(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [
            'order_id' => 'RD-NOTE-META-001',
        ]);

        Remark::query()->create([
            'user_id' => $agent->id,
            'remarkable_type' => $incident->getMorphClass(),
            'remarkable_id' => $incident->id,
            'body' => 'Visible note text only.',
            'metadata' => [
                'reminder' => ['message' => 'secret-reminder-token'],
            ],
        ]);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'secret-reminder-token']))
            ->assertOk()
            ->assertJsonPath('match_count', 0);
    }

    public function test_search_finds_closed_service_cases(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [
            'order_id' => 'RD-CLOSED-001',
        ], [
            'status' => IncidentStatus::Closed,
        ]);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RD-CLOSED-001']))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('incident_ids.0', $incident->id);
    }

    public function test_search_finds_resolved_service_cases(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [
            'order_id' => 'RD-RESOLVED-001',
        ], [
            'status' => IncidentStatus::Resolved,
        ]);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RD-RESOLVED-001']))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('incident_ids.0', $incident->id);
    }

    public function test_search_finds_service_case_by_five_digit_reference_variants(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [], [
            'reference_no' => 'SC02865',
        ]);

        foreach (['SC02865', 'SC2865', 'SC-02865', 'SC-2865', '2865'] as $query) {
            $this->actingAs($agent)
                ->getJson(route('search.index', ['q' => $query]))
                ->assertOk()
                ->assertJsonPath('match_count', 1, "Failed for query: {$query}")
                ->assertJsonPath('incident_ids.0', $incident->id, "Failed for query: {$query}");
        }
    }

    public function test_agent_can_find_another_agents_active_case(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $otherAgent = User::factory()->create();
        $otherAgent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $otherCase = $this->createServiceCase($otherAgent, [
            'order_id' => 'RD-OTHER-AGENT-001',
            'customer_phone' => '9000000002',
        ], [
            'assigned_to_user_id' => $otherAgent->id,
        ]);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => '9000000002']))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('incident_ids.0', $otherCase->id)
            ->assertJsonPath('results.0.assigned_to', $otherAgent->name);
    }

    public function test_search_ignores_dashboard_view_and_filter_parameters(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $otherAgent = User::factory()->create();
        $otherAgent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $otherCase = $this->createServiceCase($otherAgent, [
            'order_id' => 'RD-VIEW-IGNORE-001',
        ], [
            'assigned_to_user_id' => $otherAgent->id,
        ]);

        $this->actingAs($agent)
            ->getJson(route('search.index', [
                'q' => 'RD-VIEW-IGNORE-001',
                'view' => 'my_work',
                'filter' => 'pending_support',
            ]))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('incident_ids.0', $otherCase->id);
    }

    public function test_search_returns_maximum_twenty_rows(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        for ($index = 1; $index <= 25; $index++) {
            $this->createServiceCase($agent, [
                'order_id' => 'RD-GLOBAL-BULK-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'customer_phone' => '8800000'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            ]);
        }

        $response = $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => '8800000']));

        $response->assertOk();
        $response->assertJsonCount(UniversalSearchService::RESULT_LIMIT, 'results');
        $response->assertJsonPath('match_count', UniversalSearchService::RESULT_LIMIT);
    }

    public function test_search_ranks_exact_matches_before_prefix_and_contains(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $containsMatch = $this->createServiceCase($agent, [
            'order_id' => 'RD-PREFIX-001',
            'serial_number' => 'ZZ-RD3434509-ZZ',
        ]);

        $prefixMatch = $this->createServiceCase($agent, [
            'order_id' => 'RD3434509-ALT',
        ]);

        $exactMatch = $this->createServiceCase($agent, [
            'order_id' => 'RD3434509',
        ]);

        $response = $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RD3434509']));

        $response->assertOk();
        $response->assertJsonPath('incident_ids.0', $exactMatch->id);
        $response->assertJsonPath('incident_ids.1', $prefixMatch->id);
        $response->assertJsonPath('incident_ids.2', $containsMatch->id);
    }

    public function test_search_returns_empty_payload_for_blank_query(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => '']))
            ->assertOk()
            ->assertJsonPath('match_count', 0)
            ->assertJsonPath('results', []);
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
            ->assertJsonPath('results', []);
    }

    public function test_navbar_includes_global_search_url_data_attribute(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('search.index'), false)
            ->assertSee('data-search-url', false);
    }

    public function test_search_result_includes_required_display_fields(): void
    {
        $agent = User::factory()->create(['name' => 'Search Agent']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [
            'order_id' => 'RD-FIELDS-001',
            'customer_name' => 'Field Customer',
            'customer_phone' => '9111222333',
        ], [
            'reference_no' => 'SC-01234',
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RD-FIELDS-001']))
            ->assertOk()
            ->assertJsonPath('results.0.service_case', $incident->display_reference)
            ->assertJsonPath('results.0.reference_number', 'SC-01234')
            ->assertJsonPath('results.0.order_id', 'RD-FIELDS-001')
            ->assertJsonPath('results.0.customer', 'Field Customer')
            ->assertJsonPath('results.0.phone', '9111222333')
            ->assertJsonPath('results.0.assigned_to', 'Search Agent')
            ->assertJsonPath('results.0.status', IncidentStatus::Open->label())
            ->assertJsonStructure(['results' => [['age', 'url']]]);
    }
}
