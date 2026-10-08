<?php

namespace Tests\Feature;

use App\Enums\AssignmentOrigin;
use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Models\AuditLog;
use App\Models\HardwareFulfilment;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Assignment\HardwareOrderServiceCaseAssignmentCoordinator;
use App\Services\IncidentReferenceService;
use App\Services\Operations\PresenceEngineService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\ServiceCaseAutomationGraceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HardwareOrderServiceCaseAssignmentRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'service_case_assignment.automation_grace_period_enabled' => true,
            'service_case_assignment.round_robin_enabled' => true,
            'service_case_assignment.hardware_order.assignee_email' => '',
        ]);
    }

    private function createHardwareOperator(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        return $user;
    }

    private function createSupportAgent(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        app(PresenceEngineService::class)->startSession($user);

        return $user->fresh();
    }

    private function createCommerceOrderForIncident(Incident $incident, string $sourceId): CommerceOrder
    {
        return CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'idempotency_key' => 'test-'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::Validated,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Test Customer',
            'order_value' => 2549,
            'support_order_id' => $incident->order_id,
            'received_at' => now(),
        ]);
    }

    private function createHardwareIncident(string $orderId, ?User $actor = null): Incident
    {
        $actor ??= User::factory()->create();

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'order_record_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Hardware routing test — '.$orderId,
            'description' => 'Hardware routing test.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'created_by' => $actor->id,
        ]);
    }

    public function test_hardware_order_with_missing_serial_routes_to_capability_operator_not_support(): void
    {
        $operator = $this->createHardwareOperator('hardware-a@test.com', 'Hardware Alpha');
        $this->createSupportAgent('agent-a@test.com', 'Agent Alpha');

        $incident = $this->createHardwareIncident('RBP851');
        $result = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $incident->creator);

        $this->assertSame($operator->id, $result->assigned_to_user_id);
        $this->assertSame(AssignmentOrigin::HardwareFulfilment, $result->assignment_origin);
    }

    public function test_rbp_rde_and_rdp_prefixes_use_hardware_routing(): void
    {
        $operator = $this->createHardwareOperator('hardware-a@test.com', 'Hardware Alpha');
        $this->createSupportAgent('agent-a@test.com', 'Agent Alpha');

        foreach (['RBP851', 'RDE253851', 'RDP1001'] as $orderId) {
            $incident = $this->createHardwareIncident($orderId);
            $result = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $incident->creator);
            $this->assertSame($operator->id, $result->assigned_to_user_id, $orderId);
        }
    }

    public function test_configured_hardware_email_overrides_capability_operator(): void
    {
        config(['service_case_assignment.hardware_order.assignee_email' => 'configured@test.com']);

        $capability = $this->createHardwareOperator('capability@test.com', 'Capability Op');
        $configured = $this->createHardwareOperator('configured@test.com', 'Configured Op');
        $this->createSupportAgent('agent-a@test.com', 'Agent Alpha');

        $incident = $this->createHardwareIncident('RBP900');
        $result = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $incident->creator);

        $this->assertSame($configured->id, $result->assigned_to_user_id);
        $this->assertNotSame($capability->id, $result->assigned_to_user_id);
    }

    public function test_invalid_configured_email_falls_back_to_capability_operator(): void
    {
        config(['service_case_assignment.hardware_order.assignee_email' => 'missing@test.com']);

        $operator = $this->createHardwareOperator('hardware-a@test.com', 'Hardware Alpha');
        $this->createSupportAgent('agent-a@test.com', 'Agent Alpha');

        $incident = $this->createHardwareIncident('RBP901');
        $result = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $incident->creator);

        $this->assertSame($operator->id, $result->assigned_to_user_id);
    }

    public function test_support_agent_without_hardware_permission_never_receives_hardware_serial_work(): void
    {
        $this->createHardwareOperator('hardware-a@test.com', 'Hardware Alpha');
        $agent = $this->createSupportAgent('agent-a@test.com', 'Agent Alpha');

        Carbon::setTestNow(Carbon::parse('2026-10-08 12:57:00', 'Asia/Kolkata'));

        $incident = $this->createHardwareIncident('RBP902');
        $incident->update([
            'automation_pending_until' => now()->subSecond(),
        ]);

        app(ServiceCaseAutomationGraceService::class)->processExpiredGracePeriods();

        $incident->refresh();
        $this->assertNotSame($agent->id, $incident->assigned_to_user_id);
        $this->assertSame(AssignmentOrigin::HardwareFulfilment, $incident->assignment_origin);
    }

    public function test_grace_expiry_defers_when_fulfilment_ingest_pending(): void
    {
        $this->createSupportAgent('agent-a@test.com', 'Agent Alpha');

        Carbon::setTestNow(Carbon::parse('2026-10-08 12:57:00', 'Asia/Kolkata'));

        $incident = $this->createHardwareIncident('RBP903');
        $incident->update([
            'automation_pending_until' => now()->subSecond(),
        ]);

        config(['service_case_assignment.hardware_order.assignee_email' => '']);

        app(ServiceCaseAutomationGraceService::class)->processExpiredGracePeriods();

        $incident->refresh();
        $this->assertNull($incident->assigned_to_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => HardwareOrderServiceCaseAssignmentCoordinator::AUDIT_DEFERRED,
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_fulfilment_ingest_retries_misassigned_support_case(): void
    {
        $operator = $this->createHardwareOperator('hardware-a@test.com', 'Hardware Alpha');
        $support = $this->createSupportAgent('support@test.com', 'Support Only');

        $incident = $this->createHardwareIncident('RBP904');
        $incident->update([
            'assigned_to_user_id' => $support->id,
            'assignment_origin' => AssignmentOrigin::Support,
        ]);

        $commerce = $this->createCommerceOrderForIncident($incident, 'RBP904');

        $fulfilment = HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => 'radiumbox_com',
            'source_type' => 'commerce_order',
            'source_id' => 'RBP904',
            'idempotency_key' => 'test-rbp904',
            'state' => HardwareFulfilmentState::ReadyForFulfilment,
            'support_order_id' => $incident->order_id,
            'ingested_at' => now(),
            'ready_at' => now(),
        ]);

        app(HardwareOrderServiceCaseAssignmentCoordinator::class)->retryAfterFulfilmentIngest($fulfilment);

        $incident->refresh();
        $this->assertSame($operator->id, $incident->assigned_to_user_id);
        $this->assertSame(AssignmentOrigin::HardwareFulfilment, $incident->assignment_origin);
    }

    public function test_manual_assignment_is_not_overwritten_by_fulfilment_retry(): void
    {
        $operator = $this->createHardwareOperator('hardware-a@test.com', 'Hardware Alpha');
        $manualOwner = $this->createSupportAgent('manual-owner@test.com', 'Manual Owner');

        $incident = $this->createHardwareIncident('RBP905');
        $incident->update([
            'assigned_to_user_id' => $manualOwner->id,
            'assignment_origin' => AssignmentOrigin::Manual,
        ]);

        $commerce = $this->createCommerceOrderForIncident($incident, 'RBP905');

        $fulfilment = HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => 'radiumbox_com',
            'source_type' => 'commerce_order',
            'source_id' => 'RBP905',
            'idempotency_key' => 'test-rbp905',
            'state' => HardwareFulfilmentState::ReadyForFulfilment,
            'support_order_id' => $incident->order_id,
            'ingested_at' => now(),
            'ready_at' => now(),
        ]);

        app(HardwareOrderServiceCaseAssignmentCoordinator::class)->retryAfterFulfilmentIngest($fulfilment);

        $incident->refresh();
        $this->assertSame($manualOwner->id, $incident->assigned_to_user_id);
        $this->assertSame(AssignmentOrigin::Manual, $incident->assignment_origin);
        $this->assertNotSame($operator->id, $incident->assigned_to_user_id);
    }

    public function test_non_hardware_support_case_still_uses_support_round_robin(): void
    {
        $this->createHardwareOperator('hardware-a@test.com', 'Hardware Alpha');
        $agent = $this->createSupportAgent('agent-a@test.com', 'Agent Alpha');
        $this->createSupportAgent('agent-b@test.com', 'Agent Beta');

        config(['service_case_assignment.automation_grace_period_enabled' => false]);

        $actor = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-900001',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'order_record_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Service routing test',
            'description' => 'Service routing test.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'created_by' => $actor->id,
        ]);

        $result = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $actor);

        $this->assertSame($agent->id, $result->assigned_to_user_id);
        $this->assertSame(AssignmentOrigin::Support, $result->assignment_origin);
    }

    public function test_hardware_order_with_serial_allocated_keeps_existing_routing_path(): void
    {
        config(['service_case_assignment.hardware_order.assignee_email' => 'configured@test.com']);
        $operator = $this->createHardwareOperator('configured@test.com', 'Configured Op');
        $this->createSupportAgent('agent-a@test.com', 'Agent Alpha');

        $actor = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RBP906',
            'serial_number' => '12345678',
            'product_name' => 'Mantra MFS 100',
            'device_model' => 'Mantra MFS 100',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'order_record_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Hardware with serial',
            'description' => 'Hardware with serial.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'created_by' => $actor->id,
        ]);

        $result = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $actor);

        $this->assertSame($operator->id, $result->assigned_to_user_id);
    }

    public function test_assignment_audit_distinguishes_hardware_from_support(): void
    {
        $this->createHardwareOperator('hardware-a@test.com', 'Hardware Alpha');

        $incident = $this->createHardwareIncident('RBP907');
        app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $incident->creator);

        $log = AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.assigned')
            ->first();

        $this->assertSame('order_routing', $log->new_values['assignment_method'] ?? null);
        $this->assertSame('hardware_order', $log->new_values['assignment_rule'] ?? null);

        $incident->refresh();
        $this->assertSame(AssignmentOrigin::HardwareFulfilment, $incident->assignment_origin);
    }
}
