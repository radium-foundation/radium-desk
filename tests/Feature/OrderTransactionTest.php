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

class OrderTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_can_assign_transaction_id_and_lock_order(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-TXN-001',
            'serial_number' => 'SN-TXN-001',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-12345',
            ])
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('status', 'order-transaction-assigned');

        $order->refresh();
        $this->assertSame('TXN-12345', $order->transaction_id);
        $this->assertNotNull($order->completed_at);
        $this->assertSame($admin->id, $order->transaction_assigned_by);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'transaction.assigned',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
        ]);
    }

    public function test_assigning_transaction_id_closes_active_service_case(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-TXN-CLOSE',
            'serial_number' => 'SN-TXN-CLOSE',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-TXN-CLOSE',
            'category' => 'General',
            'source' => \App\Enums\IncidentSource::Call,
            'title' => 'Activation pending',
            'description' => 'Awaiting transaction ID.',
            'status' => IncidentStatus::InProgress->value,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-CLOSE-1',
            ])
            ->assertRedirect(route('orders.show', $order));

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.status_changed',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_assigning_transaction_id_closes_all_active_service_cases_on_order(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-TXN-MULTI',
            'serial_number' => 'SN-TXN-MULTI',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $first = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-TXN-M1',
            'category' => 'General',
            'source' => \App\Enums\IncidentSource::Call,
            'title' => 'First active case',
            'description' => 'First.',
            'status' => IncidentStatus::Open->value,
            'created_by' => $admin->id,
        ]);

        $second = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-TXN-M2',
            'category' => 'General',
            'source' => \App\Enums\IncidentSource::Email,
            'title' => 'Second active case',
            'description' => 'Second.',
            'status' => IncidentStatus::InProgress->value,
            'created_by' => $admin->id,
        ]);

        $alreadyClosed = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-TXN-M3',
            'category' => 'General',
            'source' => \App\Enums\IncidentSource::Call,
            'title' => 'Prior closed case',
            'description' => 'Already closed.',
            'status' => IncidentStatus::Closed->value,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-MULTI-1',
            ])
            ->assertRedirect(route('orders.show', $order));

        $this->assertSame(IncidentStatus::Closed, $first->fresh()->status);
        $this->assertSame(IncidentStatus::Closed, $second->fresh()->status);
        $this->assertSame(IncidentStatus::Closed, $alreadyClosed->fresh()->status);
        $this->assertSame(2, AuditLog::query()
            ->where('event', 'service_case.status_changed')
            ->where('auditable_type', $first->getMorphClass())
            ->whereIn('auditable_id', [$first->id, $second->id])
            ->count());
    }

    public function test_assigning_transaction_id_closes_resolved_service_case(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-TXN-RESOLVED',
            'serial_number' => 'SN-TXN-RESOLVED',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-TXN-RESOLVED',
            'category' => 'General',
            'source' => \App\Enums\IncidentSource::Call,
            'title' => 'Resolved before activation',
            'description' => 'Agent resolved pending admin completion.',
            'status' => IncidentStatus::Resolved->value,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-RESOLVED-1',
            ])
            ->assertRedirect(route('orders.show', $order));

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.status_changed',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_admin_can_assign_transaction_via_json_for_dashboard_inline_edit(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-TXN-AJAX',
            'serial_number' => 'SN-TXN-AJAX',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-AJAX-1',
            'category' => 'General',
            'source' => \App\Enums\IncidentSource::Call,
            'title' => 'AJAX assign',
            'description' => 'AJAX assign.',
            'status' => 'open',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->postJson(route('orders.transaction.store', $order), [
                'transaction_id' => 'TX123456',
                'incident_id' => $incident->id,
            ])
            ->assertOk()
            ->assertJsonPath('order_id', $order->id)
            ->assertJsonPath('incident_id', $incident->id)
            ->assertJsonStructure(['row_html', 'kpi_strip_html']);

        $order->refresh();
        $this->assertSame('TX123456', $order->transaction_id);
        $this->assertNotNull($order->completed_at);
        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
    }

    public function test_admin_can_bulk_assign_transaction_to_multiple_service_cases(): void
    {
        $admin = User::factory()->create(['name' => 'Bulk Admin']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $incidents = collect(range(1, 3))->map(function (int $index) use ($admin) {
            $order = Order::query()->create([
                'order_id' => "RD-BULK-{$index}",
                'serial_number' => "SN-BULK-{$index}",
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'status' => 'active',
                'created_by' => $admin->id,
            ]);

            return Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => "SC-BULK-{$index}",
                'category' => 'General',
                'source' => \App\Enums\IncidentSource::Call,
                'title' => "Bulk case {$index}",
                'description' => "Bulk case {$index}.",
                'status' => 'open',
                'created_by' => $admin->id,
            ]);
        });

        $alreadyCompletedOrder = Order::query()->create([
            'order_id' => 'RD-BULK-DONE',
            'serial_number' => 'SN-BULK-DONE',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TX-OLD',
            'completed_at' => now(),
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $completedIncident = Incident::query()->create([
            'order_id' => $alreadyCompletedOrder->id,
            'reference_no' => 'SC-BULK-DONE',
            'category' => 'General',
            'source' => \App\Enums\IncidentSource::Email,
            'title' => 'Already completed',
            'description' => 'Already completed.',
            'status' => 'open',
            'created_by' => $admin->id,
        ]);

        $incidentIds = $incidents->pluck('id')->push($completedIncident->id)->all();

        $response = $this->actingAs($admin)
            ->postJson(route('dashboard.transactions.bulk'), [
                'incident_ids' => $incidentIds,
                'transaction_id' => 'TX123456',
            ])
            ->assertOk()
            ->assertJsonPath('count', 3)
            ->assertJsonPath('transaction_id', 'TX123456')
            ->assertJsonStructure(['rows', 'kpi_strip_html']);

        $this->assertStringContainsString(
            'Transaction TX123456 applied to 3 service cases.',
            $response->json('message')
        );

        foreach ($incidents as $incident) {
            $this->assertSame('TX123456', $incident->order->fresh()->transaction_id);
            $this->assertDatabaseHas('audit_logs', [
                'event' => 'transaction.assigned',
                'auditable_type' => $incident->order->getMorphClass(),
                'auditable_id' => $incident->order_id,
            ]);
        }

        $this->assertSame('TX-OLD', $alreadyCompletedOrder->fresh()->transaction_id);
    }

    public function test_agent_cannot_bulk_assign_transactions(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-BULK-AGENT',
            'serial_number' => 'SN-BULK-AGENT',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-BULK-AGENT',
            'category' => 'General',
            'source' => \App\Enums\IncidentSource::Call,
            'title' => 'Agent bulk',
            'description' => 'Agent bulk.',
            'status' => 'open',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->postJson(route('dashboard.transactions.bulk'), [
                'incident_ids' => [$incident->id],
                'transaction_id' => 'TX123456',
            ])
            ->assertForbidden();
    }

    public function test_locked_order_cannot_be_edited_by_admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-TXN-002',
            'serial_number' => 'SN-TXN-002',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TXN-LOCKED',
            'completed_at' => now(),
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->put(route('orders.update', $order), [
                'order_id' => 'RD-TXN-002',
                'serial_number' => 'SN-TXN-002',
                'product_name' => 'MFS110',
                'device_model' => 'MFS110',
                'status' => 'active',
            ])
            ->assertForbidden();
    }

    public function test_locked_order_cannot_be_edited_by_agent(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-TXN-AGENT',
            'serial_number' => 'SN-TXN-AGENT',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TXN-AGENT-LOCK',
            'completed_at' => now(),
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->put(route('orders.update', $order), [
                'order_id' => 'RD-TXN-AGENT',
                'serial_number' => 'SN-TXN-AGENT',
                'product_name' => 'MFS110',
                'device_model' => 'MFS110',
                'status' => 'active',
            ])
            ->assertForbidden();
    }

    public function test_superadmin_can_edit_completed_order_without_unlocking(): void
    {
        $superadmin = User::factory()->create(['name' => 'Ravi S', 'first_name' => 'Ravi', 'last_name' => 'S']);
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $completedAt = now()->subDay();

        $order = Order::query()->create([
            'order_id' => 'RD-TXN-004',
            'serial_number' => '9393471',
            'product_name' => 'MFS 100',
            'device_model' => 'MFS 100',
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '9876543210',
            'transaction_id' => 'TXN-COMPLETE',
            'completed_at' => $completedAt,
            'transaction_assigned_by' => $superadmin->id,
            'status' => 'active',
            'created_by' => $superadmin->id,
        ]);

        $this->actingAs($superadmin)
            ->get(route('orders.edit', $order))
            ->assertOk()
            ->assertSee('Completed Order')
            ->assertSee('This order has already been completed.')
            ->assertSee('Any changes made by a Super Admin will be permanently recorded in the Audit Log.')
            ->assertSee('Reason for correction');

        $this->actingAs($superadmin)
            ->put(route('orders.update', $order), [
                'order_id' => 'RD-TXN-004',
                'serial_number' => '9393478',
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'customer_name' => 'Jane Smith',
                'customer_email' => 'jane.smith@example.com',
                'customer_phone' => '9988776655',
                'status' => 'active',
                'correction_reason' => 'Customer submitted corrected label.',
            ])
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('status', 'order-updated');

        $order->refresh();
        $this->assertSame('9393478', $order->serial_number);
        $this->assertSame('MFS 110', $order->product_name);
        $this->assertSame('Jane Smith', $order->customer_name);
        $this->assertSame('9988776655', $order->customer_phone);
        $this->assertSame('TXN-COMPLETE', $order->transaction_id);
        $this->assertNotNull($order->completed_at);
        $this->assertSame(
            $completedAt->toDateTimeString(),
            $order->completed_at->toDateTimeString(),
        );

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'order.updated',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'user_id' => $superadmin->id,
        ]);

        $auditLog = \App\Models\AuditLog::query()
            ->where('event', 'order.updated')
            ->where('auditable_id', $order->id)
            ->latest('id')
            ->first();

        $this->assertSame('9393471', $auditLog->old_values['serial_number']);
        $this->assertSame('9393478', $auditLog->new_values['serial_number']);
        $this->assertSame('9876543210', $auditLog->old_values['customer_phone']);
        $this->assertSame('9988776655', $auditLog->new_values['customer_phone']);
        $this->assertSame('MFS 100', $auditLog->old_values['product_name']);
        $this->assertSame('MFS 110', $auditLog->new_values['product_name']);
        $this->assertSame('Customer submitted corrected label.', $auditLog->new_values['correction_reason']);

        $this->actingAs($superadmin)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Updated Order Information')
            ->assertSee('Super Admin Ravi')
            ->assertSee('9393471 → 9393478')
            ->assertSee('9876543210 → 9988776655')
            ->assertSee('MFS 100 → MFS 110')
            ->assertSee('Customer submitted corrected label.');
    }

    public function test_completed_order_edit_requires_correction_reason(): void
    {
        $superadmin = User::factory()->create();
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-TXN-REASON',
            'serial_number' => 'SN-TXN-REASON',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TXN-REASON',
            'completed_at' => now(),
            'transaction_assigned_by' => $superadmin->id,
            'status' => 'active',
            'created_by' => $superadmin->id,
        ]);

        $this->actingAs($superadmin)
            ->put(route('orders.update', $order), [
                'order_id' => 'RD-TXN-REASON',
                'serial_number' => 'SN-TXN-REASON-NEW',
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'status' => 'active',
            ])
            ->assertSessionHasErrors('correction_reason');
    }

    public function test_superadmin_can_unlock_completed_order(): void
    {
        $superadmin = User::factory()->create();
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-TXN-003',
            'serial_number' => 'SN-TXN-003',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TXN-UNLOCK',
            'completed_at' => now(),
            'transaction_assigned_by' => $superadmin->id,
            'status' => 'active',
            'created_by' => $superadmin->id,
        ]);

        $this->actingAs($superadmin)
            ->delete(route('orders.transaction.destroy', $order), [
                'reason' => 'Correction required',
            ])
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('status', 'order-transaction-unlocked');

        $order->refresh();
        $this->assertNull($order->transaction_id);
        $this->assertNull($order->completed_at);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'transaction.unlocked',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
        ]);
    }
}
