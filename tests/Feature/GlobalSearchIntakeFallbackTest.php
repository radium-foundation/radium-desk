<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerIntakeSearchService;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GlobalSearchIntakeFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
        ]);
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
            'customer_phone' => $orderAttributes['customer_phone'] ?? null,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $incidentAttributes['reference_no'] ?? app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Search fallback test case',
            'description' => 'Search fallback test case.',
            'status' => $incidentAttributes['status'] ?? IncidentStatus::Open,
            'created_by' => $user->id,
            'assigned_to_user_id' => $incidentAttributes['assigned_to_user_id'] ?? $user->id,
        ]);
    }

    public function test_active_service_case_search_is_unchanged(): void
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
            ->assertJsonMissingPath('intake');
    }

    public function test_closed_service_case_search_is_unchanged(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [
            'order_id' => 'RD3437143',
        ], [
            'status' => IncidentStatus::Closed,
        ]);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RD3437143']))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('incident_ids.0', $incident->id)
            ->assertJsonMissingPath('intake');
    }

    public function test_missing_desk_order_returns_legacy_intake_fallback(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response($this->legacyOrderApiResponse()),
        ]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RD3395988']))
            ->assertOk()
            ->assertJsonPath('match_count', 0)
            ->assertJsonPath('incident_ids', [])
            ->assertJsonPath('intake.classification', 'legacy')
            ->assertJsonPath('intake.requires_confirmation', true)
            ->assertJsonPath('intake.legacy_preview.order_id', 'RD3395988')
            ->assertJsonPath('intake.legacy_preview_complete', true)
            ->assertJsonPath('intake.missing_fields', [])
            ->assertJsonPath('intake.default_source', 'call')
            ->assertJsonPath('intake.create_url', route('service-requests.quick.store'))
            ->assertJsonPath('intake.parsed_query.order_id', 'RD3395988');

        Http::assertSentCount(1);
    }

    public function test_incomplete_legacy_preview_is_not_one_click_eligible(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response($this->legacyOrderApiResponseWithoutSerial()),
        ]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RD3395988']))
            ->assertOk()
            ->assertJsonPath('intake.legacy_preview_complete', false)
            ->assertJsonPath('intake.missing_fields', ['serial_number']);
    }

    public function test_unknown_phone_returns_new_contact_intake_state(): void
    {
        Http::fake();

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => '9876501234']))
            ->assertOk()
            ->assertJsonPath('match_count', 0)
            ->assertJsonPath('incident_ids', [])
            ->assertJsonPath('intake.classification', 'new_contact')
            ->assertJsonPath('intake.requires_confirmation', false)
            ->assertJsonPath('intake.legacy_preview', null)
            ->assertJsonPath('intake.parsed_query.phone', '9876501234');

        Http::assertNothingSent();
    }

    public function test_unknown_query_returns_new_contact_intake_state(): void
    {
        Http::fake();

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'Unknown Customer Name']))
            ->assertOk()
            ->assertJsonPath('match_count', 0)
            ->assertJsonPath('incident_ids', [])
            ->assertJsonPath('intake.classification', 'new_contact')
            ->assertJsonPath('intake.requires_confirmation', false)
            ->assertJsonPath('intake.legacy_preview', null);

        Http::assertNothingSent();
    }

    public function test_intake_parsed_query_includes_email_for_email_search(): void
    {
        Http::fake();

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'new.customer@example.com']))
            ->assertOk()
            ->assertJsonPath('match_count', 0)
            ->assertJsonPath('intake.parsed_query.email', 'new.customer@example.com')
            ->assertJsonPath('intake.parsed_query.phone', null)
            ->assertJsonPath('intake.parsed_query.order_id', null);

        Http::assertNothingSent();
    }

    public function test_intake_parsed_query_maps_phone_order_and_serial(): void
    {
        $service = app(CustomerIntakeSearchService::class);

        $this->assertSame('9876543210', $service->parseQuery('9876543210')['phone']);
        $this->assertSame('RD3434509', $service->parseQuery('RD3434509')['order_id']);
        $this->assertSame('SCN001', $service->parseQuery('SCN001')['serial_number']);
    }

    public function test_intake_fallback_is_omitted_without_quick_create_permissions(): void
    {
        Http::fake();

        $user = User::factory()->create();
        $user->givePermissionTo('incidents.view');

        $this->actingAs($user)
            ->getJson(route('search.index', ['q' => 'Unknown Customer Name']))
            ->assertOk()
            ->assertJsonPath('match_count', 0)
            ->assertJsonMissingPath('intake');
    }

    public function test_quick_create_intake_search_still_works(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response($this->legacyOrderApiResponse('RD3421021')),
        ]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->postJson(route('service-requests.intake.search'), [
                'order_id' => 'RD3421021',
            ])
            ->assertOk()
            ->assertJsonPath('classification', 'legacy')
            ->assertJsonPath('requires_confirmation', true)
            ->assertJsonPath('legacy_preview.order_id', 'RD3421021')
            ->assertJsonPath('legacy_preview_complete', true)
            ->assertJsonPath('default_source', 'call');
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyOrderApiResponseWithoutSerial(string $orderId = 'RD3395988'): array
    {
        $response = $this->legacyOrderApiResponse($orderId);
        unset($response['data']['rd_order']['serial_no']);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyOrderApiResponse(string $orderId = 'RD3395988'): array
    {
        $userDetails = json_encode([
            'name' => 'Satyam Test',
            'phone' => '9876543210',
            'email' => 'test@example.com',
            'gst_no' => 'GSTIN123',
        ]);

        return [
            'status' => 200,
            'data' => [
                'order' => [
                    'invoicecode' => 'INV-9988',
                    'orderdate' => '2022-06-15 10:00:00',
                    'userdetails' => $userDetails,
                    'gst_no' => 'GSTIN123',
                    'status' => 'Completed',
                ],
                'rd_order' => [
                    'rdorderid' => $orderId,
                    'product_name' => 'MFS 110',
                    'serial_no' => 'SN123456',
                    'userdetails' => $userDetails,
                    'activation_year' => '2022',
                    'service_history' => ['2023', '2024'],
                    'amc_status' => 'Active',
                    'amc_year' => '2025',
                    'amc_details' => ['plan' => 'Gold'],
                    'rd_service_name' => '1 Year Unlimited',
                    'status' => 'Completed',
                    'created_at' => '2022-06-15 10:00:00',
                ],
            ],
        ];
    }
}
