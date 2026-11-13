<?php

namespace Tests\Feature\Dashboard;

use App\Enums\DashboardOemHandoffSessionStatus;
use App\Enums\IncidentSource;
use App\Models\DashboardOemHandoffSession;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardOemHandoffSessionRecoveryTest extends TestCase
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

    private function mantraIncident(User $agent, string $serial = 'SYN6419897'): Incident
    {
        $order = Order::query()->create([
            'order_id' => 'RD-RECOVER-'.uniqid(),
            'serial_number' => $serial,
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
            'title' => 'Recover test',
            'description' => 'Recover test.',
            'status' => 'open',
            'created_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);
    }

    private function draftReadySession(Incident $incident, User $agent): DashboardOemHandoffSession
    {
        return DashboardOemHandoffSession::query()->create([
            'public_id' => (string) Str::uuid(),
            'incident_id' => $incident->id,
            'user_id' => $agent->id,
            'oem' => 'mantra',
            'device_model' => 'MFS 110',
            'serial_number' => 'SYN6419897',
            'handoff_token_hash' => hash('sha256', 'synthetic-token'),
            'status' => DashboardOemHandoffSessionStatus::DraftReady,
            'expires_at' => now()->addHour(),
            'draft_submitted_at' => now(),
            'draft_payload' => [
                'oem' => 'mantra',
                'outcome' => 'found',
                'serial_number' => 'SYN6419897',
                'model' => 'MFS 110',
                'warranty_expiry_date' => '2027-01-15',
                'rd_expiry_date' => '2026-06-01',
            ],
        ]);
    }

    public function test_recovers_draft_ready_session_for_authorized_owner(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->mantraIncident($agent);
        $this->draftReadySession($incident, $agent);

        $this->actingAs($agent)->getJson(route('dashboard.service-cases.oem-handoff.recover', $incident))
            ->assertOk()
            ->assertJsonPath('recovered', true)
            ->assertJsonPath('session.draft.serial_number', 'SYN6419897')
            ->assertJsonMissingPath('session.handoff_token');
    }

    public function test_duplicate_recovery_requests_remain_idempotent(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->mantraIncident($agent);
        $this->draftReadySession($incident, $agent);

        $first = $this->actingAs($agent)->getJson(route('dashboard.service-cases.oem-handoff.recover', $incident));
        $second = $this->actingAs($agent)->getJson(route('dashboard.service-cases.oem-handoff.recover', $incident));

        $first->assertOk();
        $second->assertOk();
        $this->assertSame(
            $first->json('session.public_id'),
            $second->json('session.public_id'),
        );
    }

    public function test_other_user_receives_not_found_without_leaking_draft(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $other = User::factory()->create();
        $other->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->mantraIncident($owner);
        $this->draftReadySession($incident, $owner);

        $this->actingAs($other)->getJson(route('dashboard.service-cases.oem-handoff.recover', $incident))
            ->assertNotFound();
    }

    public function test_expired_session_is_not_recoverable(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->mantraIncident($agent);
        $session = $this->draftReadySession($incident, $agent);
        $session->expires_at = now()->subMinute();
        $session->save();

        $this->actingAs($agent)->getJson(route('dashboard.service-cases.oem-handoff.recover', $incident))
            ->assertNotFound();
    }

    public function test_reviewed_session_is_not_recoverable(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->mantraIncident($agent);
        $session = $this->draftReadySession($incident, $agent);
        $session->status = DashboardOemHandoffSessionStatus::Reviewed;
        $session->draft_reviewed_at = now();
        $session->save();

        $this->actingAs($agent)->getJson(route('dashboard.service-cases.oem-handoff.recover', $incident))
            ->assertNotFound();
    }

    public function test_cancelled_session_is_not_recoverable(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->mantraIncident($agent);
        $session = $this->draftReadySession($incident, $agent);
        $session->status = DashboardOemHandoffSessionStatus::Cancelled;
        $session->save();

        $this->actingAs($agent)->getJson(route('dashboard.service-cases.oem-handoff.recover', $incident))
            ->assertNotFound();
    }

    public function test_mismatched_order_serial_rejects_recovery(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->mantraIncident($agent);
        $session = $this->draftReadySession($incident, $agent);
        $session->serial_number = 'SYN0000001';
        $session->save();

        $this->actingAs($agent)->getJson(route('dashboard.service-cases.oem-handoff.recover', $incident))
            ->assertForbidden();
    }

    public function test_wrong_incident_route_does_not_cross_recover(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incidentA = $this->mantraIncident($agent, 'SYN1111111');
        $incidentB = $this->mantraIncident($agent, 'SYN2222222');
        $this->draftReadySession($incidentA, $agent);

        $this->actingAs($agent)->getJson(route('dashboard.service-cases.oem-handoff.recover', $incidentB))
            ->assertNotFound();
    }
}
