<?php

namespace Tests\Feature\Dashboard;

use App\Enums\IncidentSource;
use App\Enums\OemHandoffExpiryPersistStatus;
use App\Models\DashboardOemHandoffSession;
use App\Models\Incident;
use App\Models\Order;
use App\Models\ServiceReferenceOemSubscriptionExpiry;
use App\Models\User;
use App\Services\DashboardOemHandoff\ServiceReferenceOemSubscriptionExpiryPersistenceService;
use App\Services\IncidentReferenceService;
use App\Services\OrderTransactionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceReferenceOemExpiryPersistenceTest extends TestCase
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
            'dashboard_oem_handoff.expiry_persist_enabled' => true,
        ]);
    }

    private function incidentWithOrder(User $agent, array $orderAttributes = []): Incident
    {
        $order = Order::query()->create(array_merge([
            'order_id' => 'RD-OEM-EXP-'.uniqid(),
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
            'title' => 'OEM expiry persist',
            'description' => 'Test.',
            'status' => 'open',
            'created_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);
    }

    public function test_mantra_confirm_persists_rd_expiry_against_service_reference(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->incidentWithOrder($agent, [
            'transaction_id' => 'TXN-MANTRA-001',
            'serial_number' => 'SYN6419897',
        ]);

        $start = $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.store', $incident),
        )->assertOk()->json();

        $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.draft', [$incident, $start['session_public_id']]),
            [
                'handoff_token' => $start['handoff_token'],
                'outcome' => 'found',
                'rows' => [[
                    'serial_number' => 'SYN6419897',
                    'warranty_expiry_date' => '2027-01-15',
                    'rd_expiry_date' => '2026-06-01',
                    'model' => 'MFS 110',
                ]],
            ],
        )->assertOk();

        $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.review', [$incident, $start['session_public_id']]),
        )->assertOk()
            ->assertJsonPath('session.oem_expiry_persist.status', OemHandoffExpiryPersistStatus::Persisted->value);

        $this->assertDatabaseHas('service_reference_oem_subscription_expiries', [
            'service_reference' => 'TXN-MANTRA-001',
            'oem' => 'mantra',
            'subscription_expiry_date' => '2026-06-01 00:00:00',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => ServiceReferenceOemSubscriptionExpiryPersistenceService::AUDIT_EVENT_UPDATED,
        ]);
    }

    public function test_morpho_confirm_persists_fields_expiry_date(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->incidentWithOrder($agent, [
            'transaction_id' => 'TXN-MORPHO-77',
            'serial_number' => '0000I123456',
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
                    'serial_number' => '0000I123456',
                    'device_validity' => 'valid',
                    'whitelist_status' => 'whitelisted',
                    'expiry_date' => '15/01/2027',
                    'device_type' => 'L0',
                ],
            ],
        )->assertOk();

        $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.review', [$incident, $start['session_public_id']]),
        )->assertOk();

        $this->assertDatabaseHas('service_reference_oem_subscription_expiries', [
            'service_reference' => 'TXN-MORPHO-77',
            'oem' => 'morpho',
            'subscription_expiry_date' => '2027-01-15 00:00:00',
        ]);
    }

    public function test_quarantines_when_service_reference_missing(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->incidentWithOrder($agent);

        $session = $this->startAndConfirmMantra($agent, $incident, '2026-07-01');

        $this->assertSame(
            OemHandoffExpiryPersistStatus::QuarantinedMissingServiceReference->value,
            $session->oem_expiry_persist_status,
        );
        $this->assertDatabaseCount('service_reference_oem_subscription_expiries', 0);
    }

    public function test_assign_service_reference_flushes_quarantined_capture(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $incident = $this->incidentWithOrder($admin);
        $order = $incident->order;

        $this->actingAs($admin)->postJson(route('orders.legacy-verification.store', $order), [
            'confirmed' => true,
        ])->assertOk();

        $this->startAndConfirmMantra($admin, $incident, '2026-08-15');

        app(OrderTransactionService::class)->assignTransactionId($order->fresh(), 'TXN-FLUSH-42', $admin);

        $this->assertDatabaseHas('service_reference_oem_subscription_expiries', [
            'service_reference' => 'TXN-FLUSH-42',
            'subscription_expiry_date' => '2026-08-15 00:00:00',
        ]);
    }

    public function test_does_not_overwrite_valid_expiry_with_null_payload(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->incidentWithOrder($agent, ['transaction_id' => 'TXN-KEEP-1']);
        $this->startAndConfirmMantra($agent, $incident, '2026-09-01');

        $second = $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.store', $incident),
        )->assertOk()->json();

        $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.draft', [$incident, $second['session_public_id']]),
            [
                'handoff_token' => $second['handoff_token'],
                'outcome' => 'not_found',
                'rows' => [],
            ],
        )->assertOk();

        $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.review', [$incident, $second['session_public_id']]),
        )->assertOk();

        $this->assertDatabaseHas('service_reference_oem_subscription_expiries', [
            'service_reference' => 'TXN-KEEP-1',
            'subscription_expiry_date' => '2026-09-01 00:00:00',
        ]);
    }

    public function test_repeat_confirm_updates_expiry_for_same_service_reference(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->incidentWithOrder($agent, ['transaction_id' => 'TXN-UPD-1']);
        $this->startAndConfirmMantra($agent, $incident, '2026-01-01');
        $this->startAndConfirmMantra($agent, $incident, '2026-12-31');

        $this->assertDatabaseHas('service_reference_oem_subscription_expiries', [
            'service_reference' => 'TXN-UPD-1',
            'subscription_expiry_date' => '2026-12-31 00:00:00',
        ]);
        $this->assertSame(1, ServiceReferenceOemSubscriptionExpiry::query()->where('service_reference', 'TXN-UPD-1')->count());
    }

    private function startAndConfirmMantra(User $agent, Incident $incident, string $rdExpiry): DashboardOemHandoffSession
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
                    'serial_number' => 'SYN6419897',
                    'rd_expiry_date' => $rdExpiry,
                    'warranty_expiry_date' => '2027-01-01',
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
