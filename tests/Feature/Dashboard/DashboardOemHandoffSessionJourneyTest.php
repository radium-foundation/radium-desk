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
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DashboardOemHandoffSessionJourneyTest extends TestCase
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
            'dashboard_oem_handoff.session_ttl_seconds' => 600,
        ]);
    }

    private function mantraIncident(User $agent, string $serial = 'SYN6419897', string $model = 'MFS 110'): Incident
    {
        $order = Order::query()->create([
            'order_id' => 'RD-OEM-JRN-'.uniqid(),
            'serial_number' => $serial,
            'product_name' => $model,
            'device_model' => $model,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'OEM handoff journey',
            'description' => 'Journey test.',
            'status' => 'open',
            'created_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);
    }

    private function sessionPayload(Incident $incident): array
    {
        return $this->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.store', $incident),
        )->assertOk()->json();
    }

    private function draftPayload(string $token): array
    {
        return [
            'handoff_token' => $token,
            'outcome' => 'found',
            'rows' => [[
                'serial_number' => 'SYN6419897',
                'warranty_expiry_date' => '15/01/2027',
                'rd_expiry_date' => '01/06/2026',
                'model' => 'MFS 110',
            ]],
        ];
    }

    public function test_full_mantra_journey_session_draft_review_without_warranty_table(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->mantraIncident($agent);

        $start = $this->actingAs($agent)->sessionPayload($incident);

        $this->actingAs($agent)->getJson(
            route('dashboard.service-cases.oem-handoff.sessions.show', [$incident, $start['session_public_id']]),
        )->assertOk()
            ->assertJsonPath('session.status', DashboardOemHandoffSessionStatus::AwaitingVerification->value);

        $draft = $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.draft', [$incident, $start['session_public_id']]),
            $this->draftPayload($start['handoff_token']),
        )->assertOk();

        $draft->assertJsonPath('session.draft.warranty_expiry_date', '2027-01-15');
        $draft->assertJsonPath('session.draft.rd_expiry_date', '2026-06-01');
        $this->assertNotSame(
            $draft->json('session.draft.warranty_expiry_date'),
            $draft->json('session.draft.rd_expiry_date'),
        );

        $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.review', [$incident, $start['session_public_id']]),
        )->assertOk()
            ->assertJsonPath('session.status', DashboardOemHandoffSessionStatus::Reviewed->value);

        $session = DashboardOemHandoffSession::query()->where('public_id', $start['session_public_id'])->firstOrFail();
        $this->assertSame(DashboardOemHandoffSessionStatus::Reviewed, $session->status);
    }

    public function test_master_flag_disabled_blocks_session_start(): void
    {
        config(['dashboard_oem_handoff.enabled' => false]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->mantraIncident($agent);

        $service = app(DashboardOemHandoffSessionService::class);
        $this->expectException(ValidationException::class);
        $service->start($incident, $agent);
    }

    public function test_duplicate_draft_submission_is_rejected(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->mantraIncident($agent);

        $start = $this->actingAs($agent)->sessionPayload($incident);

        $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.draft', [$incident, $start['session_public_id']]),
            $this->draftPayload($start['handoff_token']),
        )->assertOk();

        $service = app(DashboardOemHandoffSessionService::class);
        $this->expectException(ValidationException::class);
        $service->submitDraft($start['handoff_token'], $agent, $this->draftPayload($start['handoff_token']));
    }

    public function test_expired_session_rejects_draft(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->mantraIncident($agent);

        $start = $this->actingAs($agent)->sessionPayload($incident);

        DashboardOemHandoffSession::query()
            ->where('public_id', $start['session_public_id'])
            ->update(['expires_at' => now()->subMinute()]);

        Carbon::setTestNow(now()->addMinutes(2));

        $service = app(DashboardOemHandoffSessionService::class);
        $this->expectException(ValidationException::class);
        $service->submitDraft($start['handoff_token'], $agent, $this->draftPayload($start['handoff_token']));

        Carbon::setTestNow();
    }

    public function test_new_session_cancels_prior_active_session_on_same_incident(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->mantraIncident($agent);

        $first = $this->actingAs($agent)->sessionPayload($incident);
        $second = $this->actingAs($agent)->sessionPayload($incident);

        $this->assertNotSame($first['handoff_token'], $second['handoff_token']);

        $stale = DashboardOemHandoffSession::query()->where('public_id', $first['session_public_id'])->firstOrFail();
        $this->assertSame(DashboardOemHandoffSessionStatus::Cancelled, $stale->status);

        $service = app(DashboardOemHandoffSessionService::class);
        $this->expectException(ValidationException::class);
        $service->submitDraft($first['handoff_token'], $agent, $this->draftPayload($first['handoff_token']));
    }

    public function test_concurrent_sessions_on_different_incidents_stay_isolated(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incidentA = $this->mantraIncident($agent, 'SYN1111111');
        $incidentB = $this->mantraIncident($agent, 'SYN2222222');

        $startA = $this->actingAs($agent)->sessionPayload($incidentA);
        $startB = $this->actingAs($agent)->sessionPayload($incidentB);

        $payloadB = [
            'handoff_token' => $startB['handoff_token'],
            'outcome' => 'found',
            'rows' => [[
                'serial_number' => 'SYN2222222',
                'warranty_expiry_date' => '2027-02-01',
                'rd_expiry_date' => '2026-07-01',
                'model' => 'MFS 110',
            ]],
        ];

        $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.draft', [$incidentA, $startA['session_public_id']]),
            [
                'handoff_token' => $startA['handoff_token'],
                'outcome' => 'found',
                'rows' => [[
                    'serial_number' => 'SYN1111111',
                    'warranty_expiry_date' => '2027-03-01',
                    'rd_expiry_date' => '2026-08-01',
                    'model' => 'MFS 110',
                ]],
            ],
        )->assertOk();

        $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.draft', [$incidentB, $startB['session_public_id']]),
            $payloadB,
        )->assertOk()
            ->assertJsonPath('session.draft.serial_number', 'SYN2222222');

        $service = app(DashboardOemHandoffSessionService::class);
        $this->expectException(ValidationException::class);
        $service->submitDraft($startB['handoff_token'], $agent, [
            'outcome' => 'found',
            'rows' => [[
                'serial_number' => 'SYN1111111',
                'warranty_expiry_date' => '2027-03-01',
                'rd_expiry_date' => '2026-08-01',
                'model' => 'MFS 110',
            ]],
        ]);
    }

    public function test_draft_token_must_match_session_public_id_in_url(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incidentA = $this->mantraIncident($agent, 'SYN3333333');
        $incidentB = $this->mantraIncident($agent, 'SYN4444444');

        $startA = $this->actingAs($agent)->sessionPayload($incidentA);
        $startB = $this->actingAs($agent)->sessionPayload($incidentB);

        $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.draft', [$incidentB, $startB['session_public_id']]),
            [
                'handoff_token' => $startA['handoff_token'],
                'outcome' => 'found',
                'rows' => [[
                    'serial_number' => 'SYN3333333',
                    'warranty_expiry_date' => '2027-01-01',
                    'rd_expiry_date' => '2026-01-01',
                    'model' => 'MFS 110',
                ]],
            ],
        )->assertForbidden();
    }

    public function test_not_found_outcome_allowed_without_dates(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->mantraIncident($agent);

        $start = $this->actingAs($agent)->sessionPayload($incident);

        $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.draft', [$incident, $start['session_public_id']]),
            [
                'handoff_token' => $start['handoff_token'],
                'outcome' => 'not_found',
                'rows' => [],
            ],
        )->assertOk()
            ->assertJsonPath('session.draft.outcome', 'not_found')
            ->assertJsonPath('session.draft.warranty_expiry_date', null);
    }
}
