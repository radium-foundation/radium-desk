<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\DeviceModelCorrection\DeviceModelCorrectionEligibilityService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\SerialCorrection\SerialCorrectionEligibilityService;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentOrderIdentityCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DeviceModelSeeder::class);
        $this->withHeaders(['Sec-Fetch-Site' => 'same-origin']);
    }

    public function test_agent_can_correct_serial_on_paid_rd_order(): void
    {
        [$agent, $incident] = $this->createPaidRdIncident('7881953', RolePermissionSeeder::ROLE_AGENT);
        $agent->givePermissionTo(RolePermissionSeeder::PERMISSION_CORRECT_ORDER_IDENTITY);

        $this->assertTrue($agent->can('correctOrderIdentity', $incident->order));
        $this->assertFalse($agent->can('correctIdentity', $incident->order));

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.correct-serial-number', $incident), [
                'serial_number' => '7881954',
                'reason' => 'Customer confirmed the correct serial on a verified call.',
                'workspace_context' => 'customer',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $order = $incident->order->fresh();
        $this->assertSame('7881954', $order->serial_number);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'serial.assigned',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'user_id' => $agent->id,
        ]);
    }

    public function test_agent_can_correct_device_model_on_paid_rd_order(): void
    {
        [$agent, $incident] = $this->createPaidRdIncident('7881953', RolePermissionSeeder::ROLE_AGENT);
        $agent->givePermissionTo(RolePermissionSeeder::PERMISSION_CORRECT_ORDER_IDENTITY);
        $replacementModel = DeviceModel::query()->where('name', 'L1')->firstOrFail();

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.correct-device-model', $incident), [
                'device_model_id' => $replacementModel->id,
                'reason' => 'Customer confirmed the correct device model on a verified call.',
                'workspace_context' => 'customer',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $order = $incident->order->fresh();
        $this->assertSame($replacementModel->id, $order->device_model_id);
        $this->assertSame('L1', $order->device_model);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'device-model.assigned',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'user_id' => $agent->id,
        ]);
    }

    public function test_agent_without_permission_cannot_correct_identity_on_paid_rd_order(): void
    {
        [$agent, $incident] = $this->createPaidRdIncident('7881953', RolePermissionSeeder::ROLE_AGENT);

        $this->assertFalse($agent->can('correctOrderIdentity', $incident->order));

        $serial = app(SerialCorrectionEligibilityService::class)->evaluate($incident, $agent);
        $deviceModel = app(DeviceModelCorrectionEligibilityService::class)->evaluate($incident, $agent);

        $this->assertFalse($serial->allowed);
        $this->assertSame('Correct Order Identity permission required.', $serial->reason);
        $this->assertFalse($deviceModel->allowed);
        $this->assertSame('Correct Order Identity permission required.', $deviceModel->reason);
    }

    public function test_agent_cannot_correct_order_id_via_order_update(): void
    {
        [$agent, $incident] = $this->createPaidRdIncident('7881953', RolePermissionSeeder::ROLE_AGENT);
        $order = $incident->order;

        $this->actingAs($agent)
            ->put(route('orders.update', $order), [
                'order_id' => 'RD-NEW-ID',
                'serial_number' => $order->serial_number,
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'status' => 'active',
            ])
            ->assertForbidden();

        $this->assertSame($order->order_id, $order->fresh()->order_id);
    }

    public function test_agent_cannot_correct_customer_fields(): void
    {
        [$agent, $incident] = $this->createPaidRdIncident('7881953', RolePermissionSeeder::ROLE_AGENT);
        $agent->givePermissionTo(RolePermissionSeeder::PERMISSION_CORRECT_ORDER_IDENTITY);

        $result = app(SerialCorrectionEligibilityService::class)->evaluate($incident, $agent);
        $this->assertTrue($result->allowed);

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'correct-customer-details',
                'workspace_context' => 'customer',
            ]))
            ->assertForbidden();
    }

    public function test_agent_cannot_correct_payment_or_reference_fields(): void
    {
        [$agent, $incident] = $this->createPaidRdIncident('7881953', RolePermissionSeeder::ROLE_AGENT);
        $order = $incident->order;

        $this->actingAs($agent)
            ->postJson(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-AGENT-001',
            ])
            ->assertForbidden();
    }

    public function test_inq_orders_remain_blocked_for_agent_identity_correction(): void
    {
        [$agent, $incident] = $this->createPaidIncident('INQ-REF-001', '7881953', RolePermissionSeeder::ROLE_AGENT);
        $agent->givePermissionTo(RolePermissionSeeder::PERMISSION_CORRECT_ORDER_IDENTITY);

        $serial = app(SerialCorrectionEligibilityService::class)->evaluate($incident, $agent);
        $deviceModel = app(DeviceModelCorrectionEligibilityService::class)->evaluate($incident, $agent);

        $this->assertFalse($serial->allowed);
        $this->assertSame('Identity correction is not available for inquiry orders.', $serial->reason);
        $this->assertFalse($deviceModel->allowed);
        $this->assertSame('Identity correction is not available for inquiry orders.', $deviceModel->reason);
    }

    public function test_rde_orders_remain_blocked_for_agent_identity_correction(): void
    {
        [$agent, $incident] = $this->createPaidIncident('RDE253851', '7881953', RolePermissionSeeder::ROLE_AGENT);
        $agent->givePermissionTo(RolePermissionSeeder::PERMISSION_CORRECT_ORDER_IDENTITY);

        $serial = app(SerialCorrectionEligibilityService::class)->evaluate($incident, $agent);

        $this->assertFalse($serial->allowed);
        $this->assertSame('Identity correction is not available for hardware orders.', $serial->reason);
    }

    public function test_unpaid_rd_orders_remain_blocked_for_agent_identity_correction(): void
    {
        [$agent, $incident] = $this->createIncident('RD-UNPAID', '7881953', RolePermissionSeeder::ROLE_AGENT);
        $agent->givePermissionTo(RolePermissionSeeder::PERMISSION_CORRECT_ORDER_IDENTITY);

        $serial = app(SerialCorrectionEligibilityService::class)->evaluate($incident, $agent);

        $this->assertFalse($serial->allowed);
        $this->assertSame('Identity correction is only available for paid orders.', $serial->reason);
    }

    public function test_agent_serial_correction_clears_waiting_customer_and_enters_ready_queue(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        [$agent, $incident] = $this->createPaidRdIncident('7881954', RolePermissionSeeder::ROLE_AGENT, assignee: $admin);
        $agent->givePermissionTo(RolePermissionSeeder::PERMISSION_CORRECT_ORDER_IDENTITY);

        app(\App\Services\IncidentWaitingStateService::class)->ensureSerialWaitingState($incident, $admin, [
            'serial_correction' => true,
        ]);

        $this->assertTrue(app(OperationsQueueClassifier::class)->isWaitingCustomer($incident->fresh()));

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.correct-serial-number', $incident), [
                'serial_number' => '7881953',
                'reason' => 'Customer confirmed the correct serial on a verified call.',
                'workspace_context' => 'customer',
            ])
            ->assertOk();

        $freshIncident = $incident->fresh(['activeWaitingState']);

        $this->assertNull($freshIncident->activeWaitingState);
        $this->assertSame(OperationQueue::ActionRequired, app(OperationsQueueClassifier::class)->classify($freshIncident));
    }

    public function test_admin_identity_permissions_remain_unchanged(): void
    {
        [$admin, $incident] = $this->createPaidRdIncident('7881953', RolePermissionSeeder::ROLE_ADMIN);

        $this->assertTrue($admin->can('correctIdentity', $incident->order));
        $this->assertTrue($admin->can('correctOrderIdentity', $incident->order));
        $this->assertTrue(app(SerialCorrectionEligibilityService::class)->evaluate($incident, $admin)->allowed);
        $this->assertTrue(app(DeviceModelCorrectionEligibilityService::class)->evaluate($incident, $admin)->allowed);
    }

    public function test_paid_rd_agent_sees_identity_correction_actions_in_customer_360(): void
    {
        [$agent, $incident] = $this->createPaidRdIncident('7881953', RolePermissionSeeder::ROLE_AGENT);
        $agent->givePermissionTo(RolePermissionSeeder::PERMISSION_CORRECT_ORDER_IDENTITY);

        $html = $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Correct Device Identity', $html);
        $this->assertStringContainsString('data-workspace-trigger="correct-serial-number"', $html);
        $this->assertStringNotContainsString('data-workspace-trigger="correct-customer-details"', $html);
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createPaidRdIncident(
        string $serial,
        string $role,
        ?User $assignee = null,
    ): array {
        return $this->createPaidIncident('RD-AGENT-'.uniqid(), $serial, $role, $assignee);
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createPaidIncident(
        string $orderId,
        string $serial,
        string $role,
        ?User $assignee = null,
    ): array {
        return $this->createIncident($orderId, $serial, $role, $assignee, paid: true);
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createIncident(
        string $orderId,
        string $serial,
        string $role,
        ?User $assignee = null,
        bool $paid = false,
    ): array {
        $user = User::factory()->create();
        $user->assignRole($role);

        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => $serial,
            'serial_entered_at' => now(),
            'serial_entered_by_user_id' => $user->id,
            'product_name' => 'MFS 110',
            'device_model' => $deviceModel->name,
            'device_model_id' => $deviceModel->id,
            'device_model_assigned_at' => now(),
            'device_model_assigned_by_user_id' => $user->id,
            'customer_name' => 'Identity Customer',
            'customer_email' => 'identity@example.com',
            'customer_phone' => '9123456782',
            'cashfree_payment_id' => $paid ? 'cf_pay_'.uniqid() : null,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Identity correction case',
            'description' => 'Identity correction case.',
            'status' => IncidentStatus::Open,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'assigned_to_user_id' => ($assignee ?? $user)->id,
        ]);

        return [$user, $incident];
    }
}
