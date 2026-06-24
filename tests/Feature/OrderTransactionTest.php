<?php

namespace Tests\Feature;

use App\Models\AuditLog;
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
