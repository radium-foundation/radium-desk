<?php

namespace Tests\Feature\Dashboard;

use App\Enums\DashboardOemHandoffSessionStatus;
use App\Enums\IncidentSource;
use App\Models\DashboardOemHandoffSession;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardOemHandoff\DashboardOemHandoffSessionService;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DashboardOemHandoffSessionSecurityTest extends TestCase
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

    private function createMantraIncident(User $agent): Incident
    {
        $order = Order::query()->create([
            'order_id' => 'RD-OEM-SEC-'.uniqid(),
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
            'title' => 'OEM handoff security',
            'description' => 'OEM handoff security test.',
            'status' => 'open',
            'created_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);
    }

    public function test_start_session_returns_token_and_binds_incident(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->createMantraIncident($agent);

        $response = $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.store', $incident),
        );

        $response->assertOk()
            ->assertJsonPath('incident_id', $incident->id)
            ->assertJsonPath('oem', 'mantra')
            ->assertJsonStructure(['handoff_token', 'session_public_id']);

        $this->assertDatabaseHas('dashboard_oem_handoff_sessions', [
            'incident_id' => $incident->id,
            'user_id' => $agent->id,
            'oem' => 'mantra',
        ]);
    }

    public function test_draft_submission_rejects_cross_user_token(): void
    {
        $agentA = User::factory()->create();
        $agentA->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $agentB = User::factory()->create();
        $agentB->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createMantraIncident($agentA);

        $start = $this->actingAs($agentA)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.store', $incident),
        )->json();

        $this->actingAs($agentB)->postJson(
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
        )->assertForbidden();
    }

    public function test_review_marks_session_without_warranty_persistence_table(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->createMantraIncident($agent);

        $start = $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.store', $incident),
        )->json();

        $this->actingAs($agent)->postJson(
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

        $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.review', [$incident, $start['session_public_id']]),
        )->assertOk();

        $session = DashboardOemHandoffSession::query()->where('public_id', $start['session_public_id'])->first();
        $this->assertSame(DashboardOemHandoffSessionStatus::Reviewed, $session?->status);
        $this->assertNotNull($session?->draft_reviewed_at);

        if (Schema::hasTable('oem_warranty_checks')) {
            $this->assertDatabaseCount('oem_warranty_checks', 0);
        }
    }

    public function test_capture_disabled_by_default_blocks_draft(): void
    {
        config([
            'dashboard_oem_handoff.enabled' => true,
            'dashboard_oem_handoff.mantra_result_capture_enabled' => false,
        ]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->createMantraIncident($agent);

        $service = app(DashboardOemHandoffSessionService::class);
        $started = $service->start($incident, $agent);

        $this->expectException(ValidationException::class);

        $service->submitDraft($started['handoff_token'], $agent, [
            'outcome' => 'found',
            'rows' => [[
                'serial_number' => '6540662',
                'warranty_expiry_date' => '2027-01-15',
                'rd_expiry_date' => '2026-06-01',
                'model' => 'MFS 110',
            ]],
        ]);
    }
}
