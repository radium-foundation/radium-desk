<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderSerialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_agent_can_assign_serial_number_from_dashboard(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-SERIAL-001',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-SERIAL-001',
            'category' => 'General',
            'source' => 'cashfree',
            'title' => 'Cashfree activation',
            'description' => 'Awaiting serial number.',
            'status' => IncidentStatus::InProgress->value,
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->postJson(route('orders.serial.store', $order), [
                'serial_number' => ' 252601401258 ',
                'incident_id' => $incident->id,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Serial Number saved and locked successfully.')
            ->assertJsonPath('incident_id', $incident->id);

        $order->refresh();
        $this->assertSame('252601401258', $order->serial_number);
        $this->assertNotNull($order->serial_entered_at);
        $this->assertSame($agent->id, $order->serial_entered_by_user_id);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'serial.assigned',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'user_id' => $agent->id,
        ]);
    }

    public function test_cannot_overwrite_locked_serial_number(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-SERIAL-LOCK',
            'serial_number' => '252601401258',
            'serial_entered_at' => now(),
            'serial_entered_by_user_id' => $admin->id,
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->postJson(route('orders.serial.store', $order), [
                'serial_number' => '999999999999',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['serial_number']);

        $order->refresh();
        $this->assertSame('252601401258', $order->serial_number);
    }

    public function test_dashboard_row_shows_plus_when_serial_missing_and_agent_can_enter(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-SERIAL-UI',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-SERIAL-UI',
            'category' => 'General',
            'source' => 'cashfree',
            'title' => 'Cashfree activation',
            'description' => 'Awaiting serial number.',
            'status' => IncidentStatus::InProgress->value,
            'created_by' => $agent->id,
        ]);

        $response = $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.row', $incident));

        $response->assertOk();
        $html = $response->json('html');
        $this->assertStringContainsString('data-inline-serial="true"', $html);
        $this->assertStringContainsString('transaction-inline-editor', $html);
        $this->assertStringContainsString('Enter serial number', $html);
    }

    public function test_dashboard_row_shows_serial_without_plus_when_locked(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-SERIAL-DONE',
            'serial_number' => '252601401258',
            'serial_entered_at' => now(),
            'serial_entered_by_user_id' => $agent->id,
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-SERIAL-DONE',
            'category' => 'General',
            'source' => 'cashfree',
            'title' => 'Cashfree activation',
            'description' => 'Serial entered.',
            'status' => IncidentStatus::InProgress->value,
            'created_by' => $agent->id,
        ]);

        $response = $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.row', $incident));

        $response->assertOk();
        $html = $response->json('html');
        $this->assertStringContainsString('252601401258', $html);
        $this->assertStringContainsString('dashboard-u-serial-value', $html);
        $this->assertStringContainsString('data-bs-title="252601401258"', $html);
        $this->assertStringNotContainsString('data-serial-copy', $html);
        $this->assertStringNotContainsString('data-inline-serial="true"', $html);
    }

    public function test_admin_can_correct_locked_serial_via_order_update(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-SERIAL-EDIT',
            'serial_number' => '7881953',
            'serial_entered_at' => now(),
            'serial_entered_by_user_id' => $admin->id,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->put(route('orders.update', $order), [
                'order_id' => $order->order_id,
                'serial_number' => '7881954',
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'status' => 'active',
            ])
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('status', 'order-updated');

        $order->refresh();
        $this->assertSame('7881954', $order->serial_number);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'order.identity.corrected',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'user_id' => $admin->id,
        ]);

        $auditLog = AuditLog::query()
            ->where('event', 'order.identity.corrected')
            ->where('auditable_id', $order->id)
            ->latest('id')
            ->first();

        $this->assertSame('7881953', $auditLog->old_values['serial_number']);
        $this->assertSame('7881954', $auditLog->new_values['serial_number']);
        $this->assertNotNull($auditLog->created_at);
    }

    public function test_admin_serial_correction_rejects_duplicate_serial(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        Order::query()->create([
            'order_id' => 'RD-SERIAL-OWNER-2',
            'serial_number' => '7881953',
            'serial_entered_at' => now(),
            'serial_entered_by_user_id' => $admin->id,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $order = Order::query()->create([
            'order_id' => 'RD-SERIAL-EDIT-DUP',
            'serial_number' => '7881954',
            'serial_entered_at' => now(),
            'serial_entered_by_user_id' => $admin->id,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->put(route('orders.update', $order), [
                'order_id' => $order->order_id,
                'serial_number' => '7881953',
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'status' => 'active',
            ])
            ->assertSessionHasErrors('serial_number');

        $order->refresh();
        $this->assertSame('7881954', $order->serial_number);
    }

    public function test_edit_order_form_shows_canonical_and_formatted_device_model(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-FORM-DISPLAY',
            'serial_number' => '252601401258',
            'product_name' => 'MFS 110 Refrigerator Cold Storage Unit',
            'device_model' => 'MFS 110 Refrigerator Cold Storage Unit',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('orders.edit', $order))
            ->assertOk()
            ->assertSee('Canonical value stored on the order', false)
            ->assertSee('MFS 110 Refrigerator Cold Storage Unit', false)
            ->assertSee('Dashboard display: MFS 110', false);
    }

    public function test_correct_identity_policy_allows_admin_operations_admin_and_superadmin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $operationsAdmin = User::factory()->create();
        $operationsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $superadmin = User::factory()->create();
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-SERIAL-POLICY',
            'serial_number' => '252601401258',
            'serial_entered_at' => now(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->assertTrue($admin->can('correctIdentity', $order));
        $this->assertTrue($operationsAdmin->can('correctIdentity', $order));
        $this->assertTrue($superadmin->can('correctIdentity', $order));
        $this->assertFalse($agent->can('correctIdentity', $order));
    }

    public function test_unlock_serial_policy_is_superadmin_only(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $superadmin = User::factory()->create();
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-SERIAL-UNLOCK',
            'serial_number' => '252601401258',
            'serial_entered_at' => now(),
            'serial_entered_by_user_id' => $admin->id,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->assertFalse($admin->can('unlockSerial', $order));
        $this->assertTrue($superadmin->can('unlockSerial', $order));
    }

    public function test_cannot_assign_serial_number_already_used_by_another_order(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD-SERIAL-OWNER',
            'serial_number' => '252601401258',
            'serial_entered_at' => now(),
            'serial_entered_by_user_id' => $agent->id,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $order = Order::query()->create([
            'order_id' => 'RD-SERIAL-DUP',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->postJson(route('orders.serial.store', $order), [
                'serial_number' => '252601401258',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['serial_number']);

        $order->refresh();
        $this->assertNull($order->serial_number);
    }

    public function test_order_show_displays_serial_entry_metadata(): void
    {
        $agent = User::factory()->create(['name' => 'Gaurav Patel']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $enteredAt = now()->subHour();

        $order = Order::query()->create([
            'order_id' => 'RD-SERIAL-SHOW',
            'serial_number' => '252601401258',
            'serial_entered_at' => $enteredAt,
            'serial_entered_by_user_id' => $agent->id,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('252601401258', false)
            ->assertSee('Gaurav Patel', false)
            ->assertSee('Entered By', false)
            ->assertSee('Entered At', false);
    }
}
