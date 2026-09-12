<?php

namespace Tests\Feature\GlobalSearch;

use App\Contracts\HistoricalSearchRepository;
use App\Data\HistoricalSearchHit;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\HistoricalSearch\HistoricalSearchCircuitBreaker;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeHistoricalSearchRepository;
use Tests\TestCase;

class HistoricalGlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        FakeHistoricalSearchRepository::reset();
        Cache::flush();

        $this->app->instance(HistoricalSearchRepository::class, new FakeHistoricalSearchRepository);

        config([
            'historical_search.enabled' => true,
            'historical_search.max_results' => 10,
        ]);
    }

    private function createServiceCase(User $user, string $orderId): Incident
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Desk Customer',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Historical search test case',
            'description' => 'Historical search test case.',
            'status' => IncidentStatus::Open,
            'created_by' => $user->id,
            'assigned_to_user_id' => $user->id,
        ]);
    }

    public function test_historical_hit_is_returned_with_provenance(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        FakeHistoricalSearchRepository::$hits = [
            new HistoricalSearchHit(
                documentType: 'order',
                entityId: 1748306,
                title: 'RS123456',
                subtitle: 'Sample Customer',
                occurredOn: '2021-09-08',
                sourceLineage: 'commerce_active',
                sourceDatabase: 'radium_old_final',
                sourceTable: 'orders',
                sourcePk: '2772',
            ),
        ];

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RS123456']))
            ->assertOk()
            ->assertJsonPath('historical_match_count', 1)
            ->assertJsonPath('historical_results.0.type', 'historical')
            ->assertJsonPath('historical_results.0.document_type', 'order')
            ->assertJsonPath('historical_results.0.source_lineage', 'commerce_active')
            ->assertJsonPath('historical_results.0.source_database', 'radium_old_final')
            ->assertJsonPath('historical_results.0.is_authoritative', false)
            ->assertJsonPath('historical_results.0.historical_only', true)
            ->assertJsonPath('historical_results.0.hist_order_id', 1748306)
            ->assertJsonPath('historical_results.0.summary_url', route('historical-orders.show', ['histOrder' => 1748306]))
            ->assertJsonPath('match_count', 0);
    }

    public function test_no_historical_hit_returns_empty_historical_results(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'NO-HISTORICAL-MATCH']))
            ->assertOk()
            ->assertJsonPath('historical_match_count', 0)
            ->assertJsonCount(0, 'historical_results');
    }

    public function test_multiple_historical_result_types_are_returned(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        FakeHistoricalSearchRepository::$hits = [
            new HistoricalSearchHit('order', 1, 'ORD-1', 'Customer A', '2020-01-01', 'commerce_box'),
            new HistoricalSearchHit('serial', 2, 'SN-ABC', 'ORD-1', null, 'rd_service', partialIngest: true),
            new HistoricalSearchHit('invoice', 3, 'INV-9', 'Supplier', '2021-08-20', 'invoice'),
        ];

        $response = $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'multi']))
            ->assertOk()
            ->assertJsonPath('historical_match_count', 3);

        $types = collect($response->json('historical_results'))->pluck('document_type')->all();
        $this->assertSame(['order', 'serial', 'invoice'], $types);
        $this->assertTrue($response->json('historical_results.1.partial_ingest'));
    }

    public function test_desk_search_still_succeeds_when_historical_provider_fails(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, 'RD-HIST-FAIL-001');
        FakeHistoricalSearchRepository::$exception = new \RuntimeException('historical db unavailable');

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RD-HIST-FAIL-001']))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('results.0.incident_id', $incident->id)
            ->assertJsonPath('historical_match_count', 0)
            ->assertJsonPath('historical_results', []);
    }

    public function test_historical_failure_opens_circuit_without_breaking_desk_search(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->createServiceCase($agent, 'RD-CB-001');
        FakeHistoricalSearchRepository::$exception = new \RuntimeException('timeout');

        config([
            'historical_search.circuit_breaker.failure_threshold' => 1,
            'historical_search.circuit_breaker.open_seconds' => 60,
        ]);

        $breaker = app(HistoricalSearchCircuitBreaker::class);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RD-CB-001']))
            ->assertOk()
            ->assertJsonPath('match_count', 1);

        $this->assertTrue($breaker->isOpen());

        FakeHistoricalSearchRepository::$exception = null;
        FakeHistoricalSearchRepository::$hits = [
            new HistoricalSearchHit('order', 99, 'SHOULD-NOT-APPEAR', '', null, 'commerce_box'),
        ];

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'SHOULD-NOT-APPEAR']))
            ->assertOk()
            ->assertJsonPath('historical_match_count', 0);
    }

    public function test_historical_search_disabled_skips_provider(): void
    {
        config(['historical_search.enabled' => false]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        FakeHistoricalSearchRepository::$hits = [
            new HistoricalSearchHit('order', 1, 'DISABLED', '', null, 'commerce_box'),
        ];

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'DISABLED']))
            ->assertOk()
            ->assertJsonPath('historical_match_count', 0)
            ->assertJsonPath('historical_search.status', 'disabled');
    }

    public function test_users_without_incidents_view_permission_receive_no_results(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_EMPLOYEE);

        FakeHistoricalSearchRepository::$hits = [
            new HistoricalSearchHit('order', 1, 'SECURE', '', null, 'commerce_box'),
        ];

        $this->actingAs($user)
            ->getJson(route('search.index', ['q' => 'SECURE']))
            ->assertOk()
            ->assertJsonPath('match_count', 0)
            ->assertJsonMissingPath('historical_results');
    }

    public function test_email_historical_hit_returns_with_provenance_fields(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        FakeHistoricalSearchRepository::$hits = [
            new HistoricalSearchHit(
                documentType: 'customer',
                entityId: 42,
                title: 'baswaraj744@gmail.com',
                subtitle: 'Customer',
                occurredOn: null,
                sourceLineage: 'commerce_box',
                sourceDatabase: 'radiumbox',
                sourceTable: 'customers',
                sourcePk: '99',
            ),
        ];

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'baswaraj744@gmail.com']))
            ->assertOk()
            ->assertJsonPath('historical_match_count', 1)
            ->assertJsonPath('historical_results.0.source_database', 'radiumbox')
            ->assertJsonPath('historical_results.0.is_authoritative', false);
    }

    public function test_desk_and_historical_results_are_both_returned(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, 'RD-BOTH-001');

        FakeHistoricalSearchRepository::$hits = [
            new HistoricalSearchHit('order', 500, 'RD-BOTH-001', 'Legacy twin', '2019-01-01', 'rd_legacy'),
        ];

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RD-BOTH-001']))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('results.0.incident_id', $incident->id)
            ->assertJsonPath('historical_match_count', 1)
            ->assertJsonPath('historical_results.0.source_lineage', 'rd_legacy');
    }
}
