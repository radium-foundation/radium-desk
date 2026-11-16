<?php

namespace Tests\Feature\Dashboard;

use App\Enums\DashboardOemHandoffSessionStatus;
use App\Enums\IncidentSource;
use App\Models\DashboardOemHandoffSession;
use App\Models\Incident;
use App\Models\Order;
use App\Models\ServiceReferenceOemSubscriptionExpiry;
use App\Models\User;
use App\Services\DashboardOemHandoff\OemHandoffAdministratorCompletionService;
use App\Services\IncidentReferenceService;
use App\Services\OrderTransactionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardOemHandoffCompleteAssignmentTest extends TestCase
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
            'dashboard_oem_handoff.morpho_result_capture_enabled' => true,
        ]);
    }

    private function mantraIncident(User $actor, string $serial = 'SYN6419897'): Incident
    {
        $order = Order::query()->create([
            'order_id' => 'RD-OEM-CMP-'.uniqid(),
            'serial_number' => $serial,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'OEM complete assignment',
            'description' => 'Test.',
            'status' => 'open',
            'created_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);
    }

    private function confirmLegacyVerification(User $actor, Order $order): void
    {
        $this->actingAs($actor)->postJson(route('orders.legacy-verification.store', $order), [
            'confirmed' => true,
        ])->assertOk();
    }

    private function draftReadySession(Incident $incident, User $actor): array
    {
        $start = $this->actingAs($actor)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.store', $incident),
        )->assertOk()->json();

        $this->actingAs($actor)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.draft', [$incident, $start['session_public_id']]),
            [
                'handoff_token' => $start['handoff_token'],
                'outcome' => 'found',
                'rows' => [[
                    'serial_number' => $incident->order?->serial_number ?? 'SYN6419897',
                    'warranty_expiry_date' => '2027-01-15',
                    'rd_expiry_date' => '2026-06-01',
                    'model' => 'MFS 110',
                ]],
            ],
        )->assertOk();

        return $start;
    }

    public function test_complete_assignment_persists_reference_expiry_audit_and_closes_order(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $incident = $this->mantraIncident($admin);
        $start = $this->draftReadySession($incident, $admin);
        $this->confirmLegacyVerification($admin, $incident->order);

        $this->actingAs($admin)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.complete-assignment', [$incident, $start['session_public_id']]),
            [
                'service_reference' => 'SRV-COMPLETE-88',
                'subscription_expiry_date' => '2026-08-20',
            ],
        )->assertOk()
            ->assertJsonPath('session.status', DashboardOemHandoffSessionStatus::Confirmed->value);

        $order = $incident->order?->fresh();
        $this->assertNotNull($order);
        $this->assertSame('SRV-COMPLETE-88', $order->transaction_id);
        $this->assertTrue($order->isTransactionLocked());

        $this->assertDatabaseHas('service_reference_oem_subscription_expiries', [
            'service_reference' => 'SRV-COMPLETE-88',
            'subscription_expiry_date' => '2026-08-20 00:00:00',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => OemHandoffAdministratorCompletionService::AUDIT_EVENT_COMPLETE,
            'user_id' => $admin->id,
        ]);

        $session = DashboardOemHandoffSession::query()->where('public_id', $start['session_public_id'])->firstOrFail();
        $this->assertNotNull($session->confirmed_payload);
        $this->assertSame('2026-06-01', $session->confirmed_payload['rd_expiry_date'] ?? null);
    }

    public function test_complete_assignment_requires_both_fields(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $incident = $this->mantraIncident($admin);
        $start = $this->draftReadySession($incident, $admin);

        $this->actingAs($admin)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.complete-assignment', [$incident, $start['session_public_id']]),
            ['service_reference' => 'SRV-MISSING-DATE'],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['subscription_expiry_date']);
    }

    public function test_complete_assignment_forbidden_without_assign_permission(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $viewer = User::factory()->create();
        $viewer->assignRole(RolePermissionSeeder::ROLE_EMPLOYEE);

        $incident = $this->mantraIncident($admin);
        $start = $this->draftReadySession($incident, $admin);

        $this->actingAs($viewer)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.complete-assignment', [$incident, $start['session_public_id']]),
            [
                'service_reference' => 'SRV-UNAUTH',
                'subscription_expiry_date' => '2026-01-01',
            ],
        )->assertForbidden();
    }

    public function test_complete_assignment_rejects_second_submit_after_confirmation(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $incident = $this->mantraIncident($admin);
        $start = $this->draftReadySession($incident, $admin);
        $this->confirmLegacyVerification($admin, $incident->order);

        $payload = [
            'service_reference' => 'SRV-DUP-GUARD',
            'subscription_expiry_date' => '2026-04-04',
        ];

        $this->actingAs($admin)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.complete-assignment', [$incident, $start['session_public_id']]),
            $payload,
        )->assertOk();

        $this->actingAs($admin)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.complete-assignment', [$incident, $start['session_public_id']]),
            $payload,
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['session']);
    }

    public function test_complete_assignment_blocks_conflicting_expiry_on_existing_reference(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        ServiceReferenceOemSubscriptionExpiry::query()->create([
            'service_reference' => 'SRV-CONFLICT',
            'oem' => 'mantra',
            'subscription_expiry_date' => '2025-12-31',
            'source_oem_handoff_session_id' => null,
            'updated_by_user_id' => $admin->id,
        ]);

        $incident = $this->mantraIncident($admin);
        $start = $this->draftReadySession($incident, $admin);
        $this->confirmLegacyVerification($admin, $incident->order);

        $this->actingAs($admin)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.complete-assignment', [$incident, $start['session_public_id']]),
            [
                'service_reference' => 'SRV-CONFLICT',
                'subscription_expiry_date' => '2026-09-09',
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['subscription_expiry_date']);

        $this->assertNull($incident->order?->fresh()->transaction_id);
    }

    public function test_complete_assignment_leaves_order_open_when_already_locked_with_different_reference(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $incident = $this->mantraIncident($admin);
        $order = $incident->order;
        $this->assertNotNull($order);

        $this->confirmLegacyVerification($admin, $order);

        app(OrderTransactionService::class)->assignTransactionId(
            order: $order,
            transactionId: 'SRV-LOCKED-FIRST',
            actor: $admin,
            broadcast: false,
        );

        $start = $this->actingAs($admin)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.store', $incident),
        )->assertOk()->json();

        $this->actingAs($admin)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.draft', [$incident, $start['session_public_id']]),
            [
                'handoff_token' => $start['handoff_token'],
                'outcome' => 'found',
                'rows' => [[
                    'serial_number' => $order->serial_number,
                    'warranty_expiry_date' => '2027-01-15',
                    'rd_expiry_date' => '2026-06-01',
                    'model' => 'MFS 110',
                ]],
            ],
        )->assertOk();

        $this->actingAs($admin)->postJson(
            route('dashboard.service-cases.oem-handoff.sessions.complete-assignment', [$incident, $start['session_public_id']]),
            [
                'service_reference' => 'SRV-LOCKED-OTHER',
                'subscription_expiry_date' => '2026-06-01',
            ],
        )->assertUnprocessable();

        $this->assertSame('SRV-LOCKED-FIRST', $order->fresh()->transaction_id);
    }
}