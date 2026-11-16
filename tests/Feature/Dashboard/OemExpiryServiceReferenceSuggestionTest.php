<?php

namespace Tests\Feature\Dashboard;

use App\Enums\IncidentSource;
use App\Models\DashboardOemHandoffSession;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardOemHandoff\OemHandoffServiceReferenceSuggestionService;
use App\Services\IncidentReferenceService;
use App\Services\OrderTransactionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OemExpiryServiceReferenceSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config([
            'dashboard_oem_handoff.enabled' => true,
            'dashboard_oem_handoff.mantra_result_capture_enabled' => true,
            'dashboard_oem_handoff.morpho_result_capture_enabled' => true,
            'dashboard_oem_handoff.expiry_persist_enabled' => false,
            'dashboard_oem_handoff.service_reference_suggestion_enabled' => true,
        ]);
    }

    private function incidentWithOrder(User $agent, array $orderAttributes = []): Incident
    {
        $order = Order::query()->create(array_merge([
            'order_id' => 'RD-OEM-SR-'.uniqid(),
            'serial_number' => 'SYN6419897',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ], $orderAttributes));

        return Incident::query()->create([
            'order_id' => $order->id,
            'order_record_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'OEM SR suggest',
            'description' => 'Test.',
            'status' => 'open',
            'created_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);
    }

    public function test_mantra_confirm_allocates_sequential_suggestions_for_same_expiry(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $first = $this->incidentWithOrder($agent);
        $second = $this->incidentWithOrder($agent, ['serial_number' => 'SYN6419898']);

        $firstSession = $this->confirmMantra($agent, $first, '2027-01-20');
        $secondSession = $this->confirmMantra($agent, $second, '2027-01-20');

        $this->assertSame('AW-20-01-27-01', $firstSession->suggested_service_reference);
        $this->assertSame('AW-20-01-27-02', $secondSession->suggested_service_reference);

        $this->assertDatabaseHas('audit_logs', [
            'event' => OemHandoffServiceReferenceSuggestionService::AUDIT_EVENT_GENERATED,
        ]);
    }

    public function test_repeat_confirm_on_same_session_does_not_reallocate_sequence(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->incidentWithOrder($agent);

        $session = $this->confirmMantra($agent, $incident, '2027-06-15');
        $suggestion = $session->suggested_service_reference;

        app(OemHandoffServiceReferenceSuggestionService::class)
            ->generateForConfirmedSession($session->fresh(), $agent);

        $this->assertSame($suggestion, $session->fresh()->suggested_service_reference);
    }

    public function test_missing_expiry_does_not_generate_suggestion(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->incidentWithOrder($agent);

        $start = $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.store', $incident),
        )->assertOk()->json();

        $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.draft', [$incident, $start['session_public_id']]),
            [
                'handoff_token' => $start['handoff_token'],
                'outcome' => 'not_found',
                'rows' => [],
            ],
        )->assertOk();

        $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.review', [$incident, $start['session_public_id']]),
        )->assertOk();

        $session = DashboardOemHandoffSession::query()->where('public_id', $start['session_public_id'])->firstOrFail();
        $this->assertNull($session->suggested_service_reference);
    }

    public function test_existing_order_reference_skips_suggestion(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->incidentWithOrder($agent, ['transaction_id' => 'LEGACY-REF-1']);

        $session = $this->confirmMantra($agent, $incident, '2027-01-20');
        $this->assertNull($session->suggested_service_reference);
    }

    public function test_assignment_uses_existing_order_transaction_service(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $incident = $this->incidentWithOrder($admin);
        $order = $incident->order;

        $this->actingAs($admin)->postJson(route('orders.legacy-verification.store', $order), [
            'confirmed' => true,
        ])->assertOk();

        $session = $this->confirmMantra($admin, $incident, '2027-03-10');

        $this->assertSame('AW-10-03-27-01', $session->suggested_service_reference);

        app(OrderTransactionService::class)->assignTransactionId(
            $order->fresh(),
            $session->suggested_service_reference,
            $admin,
        );

        $this->assertSame('AW-10-03-27-01', $order->fresh()->transaction_id);
    }

    public function test_expiry_change_requires_unlock_for_new_reference(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $incident = $this->incidentWithOrder($admin);
        $order = $incident->order;

        $this->actingAs($admin)->postJson(route('orders.legacy-verification.store', $order), [
            'confirmed' => true,
        ])->assertOk();

        $session = $this->confirmMantra($admin, $incident, '2027-01-20');
        app(OrderTransactionService::class)->assignTransactionId(
            $order->fresh(),
            (string) $session->suggested_service_reference,
            $admin,
        );

        $secondIncident = $this->incidentWithOrder($admin, [
            'serial_number' => 'SYN6419899',
            'transaction_id' => null,
        ]);

        $secondSession = $this->confirmMantra($admin, $secondIncident, '2027-02-01');
        $this->assertSame('AW-01-02-27-01', $secondSession->suggested_service_reference);
        $this->assertSame('AW-20-01-27-01', $order->fresh()->transaction_id);
    }

    public function test_morpho_confirm_returns_suggestion_in_prefill(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->incidentWithOrder($agent, [
            'serial_number' => '2441I041803',
            'device_model' => 'MSO E3',
            'product_name' => 'MSO E3',
        ]);

        $start = $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.store', $incident),
        )->assertOk()->json();

        $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.draft', [$incident, $start['session_public_id']]),
            [
                'handoff_token' => $start['handoff_token'],
                'outcome' => 'found',
                'fields' => [
                    'serial_number' => '2441I041803',
                    'device_validity' => 'Valid',
                    'whitelist_status' => 'Whitelisted',
                    'expiry_date' => '01/06/2027',
                ],
            ],
        )->assertOk();

        $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.confirm', [$incident, $start['session_public_id']]),
        )->assertOk()
            ->assertJsonPath('service_reference_prefill.suggested_transaction_id', 'AW-01-06-27-01');
    }

    private function confirmMantra(User $agent, Incident $incident, string $rdExpiry): DashboardOemHandoffSession
    {
        $start = $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.store', $incident),
        )->assertOk()->json();

        $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.draft', [$incident, $start['session_public_id']]),
            [
                'handoff_token' => $start['handoff_token'],
                'outcome' => 'found',
                'rows' => [[
                    'serial_number' => $incident->order->serial_number,
                    'rd_expiry_date' => $rdExpiry,
                    'warranty_expiry_date' => '2028-01-01',
                    'model' => 'MFS 110',
                ]],
            ],
        )->assertOk();

        $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.review', [$incident, $start['session_public_id']]),
        )->assertOk();

        return DashboardOemHandoffSession::query()->where('public_id', $start['session_public_id'])->firstOrFail();
    }
}