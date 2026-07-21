<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerCorrection\CustomerCorrectionEligibilityService;
use App\Services\Customer360\Customer360ActionVisibilityService;
use App\Services\IncidentReferenceService;
use App\Services\SerialCorrection\SerialCorrectionEligibilityService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdentityCorrectionEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_can_correct_customer_and_serial_on_active_case(): void
    {
        [$admin, $incident] = $this->createIncident('9620545', RolePermissionSeeder::ROLE_ADMIN);

        $customer = app(CustomerCorrectionEligibilityService::class)->evaluate($incident, $admin);
        $serial = app(SerialCorrectionEligibilityService::class)->evaluate($incident, $admin);

        $this->assertTrue($customer->allowed);
        $this->assertNull($customer->reason);
        $this->assertTrue($serial->allowed);
        $this->assertNull($serial->reason);
    }

    public function test_operations_admin_can_correct_identity(): void
    {
        [$operationsAdmin, $incident] = $this->createIncident('9620545', RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $this->assertTrue($operationsAdmin->can('correctIdentity', $incident->order));
        $this->assertTrue(app(CustomerCorrectionEligibilityService::class)->evaluate($incident, $operationsAdmin)->allowed);
        $this->assertTrue(app(SerialCorrectionEligibilityService::class)->evaluate($incident, $operationsAdmin)->allowed);
    }

    public function test_superadmin_can_correct_identity_on_closed_case(): void
    {
        [$superadmin, $incident] = $this->createIncident('9620545', RolePermissionSeeder::ROLE_SUPERADMIN);
        $incident->update(['status' => IncidentStatus::Closed]);

        $customer = app(CustomerCorrectionEligibilityService::class)->evaluate($incident->fresh(), $superadmin);
        $serial = app(SerialCorrectionEligibilityService::class)->evaluate($incident->fresh(), $superadmin);

        $this->assertTrue($customer->allowed);
        $this->assertTrue($serial->allowed);
    }

    public function test_admin_cannot_correct_customer_on_closed_case(): void
    {
        [$admin, $incident] = $this->createIncident('9620545', RolePermissionSeeder::ROLE_ADMIN);
        $incident->update(['status' => IncidentStatus::Closed]);

        $result = app(CustomerCorrectionEligibilityService::class)->evaluate($incident->fresh(), $admin);

        $this->assertFalse($result->allowed);
        $this->assertSame('Closed cases cannot modify customer identity.', $result->reason);
    }

    public function test_admin_cannot_correct_serial_on_closed_case(): void
    {
        [$admin, $incident] = $this->createIncident('9620545', RolePermissionSeeder::ROLE_ADMIN);
        $incident->update(['status' => IncidentStatus::Closed]);

        $result = app(SerialCorrectionEligibilityService::class)->evaluate($incident->fresh(), $admin);

        $this->assertFalse($result->allowed);
        $this->assertSame('Closed cases cannot modify serial numbers.', $result->reason);
    }

    public function test_admin_cannot_correct_customer_on_resolved_case(): void
    {
        [$admin, $incident] = $this->createIncident('9620545', RolePermissionSeeder::ROLE_ADMIN);
        $incident->update(['status' => IncidentStatus::Resolved]);

        $result = app(CustomerCorrectionEligibilityService::class)->evaluate($incident->fresh(), $admin);

        $this->assertFalse($result->allowed);
        $this->assertSame('Resolved cases cannot modify customer identity.', $result->reason);
    }

    public function test_admin_cannot_correct_serial_when_no_serial_assigned(): void
    {
        [$admin, $incident] = $this->createIncident(null, RolePermissionSeeder::ROLE_ADMIN);

        $result = app(SerialCorrectionEligibilityService::class)->evaluate($incident, $admin);

        $this->assertFalse($result->allowed);
        $this->assertSame('No serial assigned yet.', $result->reason);
    }

    public function test_admin_cannot_correct_when_case_has_no_order(): void
    {
        $admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-IDENTITY-ORPHAN',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Unlinked case',
            'description' => 'No order.',
            'status' => IncidentStatus::Open,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'assigned_to_user_id' => $admin->id,
        ]);

        $order->delete();

        $result = app(CustomerCorrectionEligibilityService::class)->evaluate($incident->fresh(), $admin);

        $this->assertFalse($result->allowed);
        $this->assertSame('This service case is not linked to an order.', $result->reason);
    }

    public function test_agent_is_denied_with_admin_permission_reason(): void
    {
        [$agent, $incident] = $this->createIncident('9620545', RolePermissionSeeder::ROLE_AGENT);

        $result = app(CustomerCorrectionEligibilityService::class)->evaluate($incident, $agent);

        $this->assertFalse($result->allowed);
        $this->assertSame('Admin permission required.', $result->reason);
    }

    public function test_customer_360_hides_unavailable_identity_actions_for_agent(): void
    {
        [$agent, $incident] = $this->createIncident('9620545', RolePermissionSeeder::ROLE_AGENT);

        $html = $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Correct Customer', $html);
        $this->assertStringNotContainsString('Correct Serial', $html);
        $this->assertStringNotContainsString('c360-quick-toolbar-more-item--disabled', $html);
        $this->assertStringNotContainsString('data-workspace-trigger="correct-customer-details"', $html);
        $this->assertStringNotContainsString('data-workspace-trigger="correct-serial-number"', $html);
    }

    public function test_customer_360_renders_enabled_identity_actions_for_admin(): void
    {
        [$admin, $incident] = $this->createIncident('9620545', RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->actingAs($admin)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-workspace-trigger="correct-customer-details"', $html);
        $this->assertStringContainsString('data-workspace-trigger="correct-serial-number"', $html);
    }

    public function test_visibility_service_exposes_eligibility_payload(): void
    {
        [$admin, $incident] = $this->createIncident(null, RolePermissionSeeder::ROLE_ADMIN);

        $visibility = app(Customer360ActionVisibilityService::class)->forIncident($incident, $admin);

        $this->assertFalse($visibility['canCorrectSerialNumber']);
        $this->assertSame('No serial assigned yet.', $visibility['correctSerialNumberEligibility']['reason']);
        $this->assertArrayHasKey('canCorrectDeviceIdentity', $visibility);
        $this->assertTrue($visibility['showIdentityCorrectionActions']);
    }

    public function test_closed_case_dialog_is_forbidden_for_admin(): void
    {
        [$admin, $incident] = $this->createIncident('9620545', RolePermissionSeeder::ROLE_ADMIN);
        $incident->update(['status' => IncidentStatus::Closed]);

        $this->actingAs($admin)
            ->get(route('incidents.components.show', [
                'incident' => $incident->fresh(),
                'component' => 'correct-serial-number',
                'workspace_context' => 'customer',
            ]))
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createIncident(?string $serial, string $role): array
    {
        $user = $this->userWithRole($role);

        $order = Order::query()->create([
            'order_id' => 'RD-IDENTITY-'.uniqid(),
            'serial_number' => $serial,
            'serial_entered_at' => $serial !== null ? now() : null,
            'serial_entered_by_user_id' => $serial !== null ? $user->id : null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'cashfree_payment_id' => $serial !== null ? 'cf_pay_'.uniqid() : null,
            'customer_name' => 'Identity Customer',
            'customer_email' => 'identity@example.com',
            'customer_phone' => '9123456782',
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
            'assigned_to_user_id' => $user->id,
        ]);

        return [$user, $incident];
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
