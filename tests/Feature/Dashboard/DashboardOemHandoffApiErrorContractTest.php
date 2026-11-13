<?php

namespace Tests\Feature\Dashboard;

use App\Enums\IncidentSource;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardOemHandoffApiErrorContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->withoutVite();
        config([
            'dashboard_oem_handoff.enabled' => true,
            'dashboard_oem_handoff.mantra_result_capture_enabled' => true,
        ]);
    }

    private function mantraIncident(User $agent): Incident
    {
        $order = Order::query()->create([
            'order_id' => 'RD-API-'.uniqid(),
            'serial_number' => '6540662',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'API contract',
            'description' => 'API contract.',
            'status' => 'open',
            'created_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);
    }

    public function test_validation_failure_returns_json_422(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->mantraIncident($agent);

        $start = $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.store', $incident),
        )->json();

        $response = $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.draft', [$incident, $start['session_public_id']]),
            [
                'handoff_token' => $start['handoff_token'],
                'outcome' => 'found',
                'rows' => [[
                    'serial_number' => '6540662',
                    'warranty_expiry_date' => '2027-01-15',
                    'rd_expiry_date' => '2026-06-01',
                    'model' => 'MFS 110',
                ]],
            ],
        )->assertOk();

        $duplicate = $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.draft', [$incident, $start['session_public_id']]),
            [
                'handoff_token' => $start['handoff_token'],
                'outcome' => 'found',
                'rows' => [[
                    'serial_number' => '6540662',
                    'warranty_expiry_date' => '2027-01-15',
                    'rd_expiry_date' => '2026-06-01',
                    'model' => 'MFS 110',
                ]],
            ],
        );

        $duplicate->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message', 'errors']);

        $response->assertJsonMissingPath('handoff_token');
    }

    public function test_cross_user_draft_returns_json_forbidden(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $other = User::factory()->create();
        $other->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->mantraIncident($owner);

        $start = $this->actingAs($owner)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.store', $incident),
        )->json();

        $this->actingAs($other)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.draft', [$incident, $start['session_public_id']]),
            [
                'handoff_token' => $start['handoff_token'],
                'outcome' => 'found',
                'rows' => [],
            ],
        )->assertForbidden()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_unknown_session_public_id_returns_json_not_found(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->mantraIncident($agent);

        $this->actingAs($agent)->getJson(
            route('dashboard.service-cases.oem-handoff.sessions.show', [$incident, '00000000-0000-4000-8000-000000000001']),
        )->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_guest_session_start_redirects_to_login(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->mantraIncident($agent);

        $this->postJson(route('dashboard.service-cases.oem-handoff.sessions.store', $incident))
            ->assertUnauthorized();
    }
}
