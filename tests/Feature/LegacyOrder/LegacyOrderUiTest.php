<?php

namespace Tests\Feature\LegacyOrder;

use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyOrderUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_legacy_imported_case_detail_renders_serial_copy_order_link_and_metadata(): void
    {
        $importer = User::factory()->create(['name' => 'Import Agent']);
        $importer->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD3395988',
            'serial_number' => 'SN123456',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'legacy_source' => 'radiumbox',
            'legacy_imported_at' => now(),
            'legacy_imported_by_user_id' => $importer->id,
            'status' => 'active',
            'created_by' => $importer->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC00088',
            'category' => 'General',
            'source' => 'call',
            'title' => 'Legacy service request',
            'description' => 'Imported legacy order.',
            'status' => 'open',
            'created_by' => $importer->id,
            'updated_by' => $importer->id,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('incidents.show', $incident))
            ->assertOk();

        $response
            ->assertDontSee('data-copyable-identifier="order-id"', false)
            ->assertDontSee('data-copy-toast="Order ID copied"', false)
            ->assertSee('data-copyable-identifier="serial"', false)
            ->assertSee('data-copy-value="SN123456"', false)
            ->assertSee('data-copy-toast="Serial number copied"', false)
            ->assertSee(route('orders.show', $order), false)
            ->assertSee('legacy-imported-order-id', false)
            ->assertSee('Legacy imported order • Imported by Import •', false)
            ->assertSee('Imported from legacy system by Import', false)
            ->assertSee(route('incidents.show', $incident), false)
            ->assertSee('SC00088', false);
    }

    public function test_legacy_imported_order_workspace_renders_styled_order_id_without_copy(): void
    {
        $importer = User::factory()->create(['name' => 'Workspace Importer']);
        $importer->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD3395999',
            'serial_number' => 'SN999999',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'legacy_source' => 'radiumbox',
            'legacy_imported_at' => now(),
            'legacy_imported_by_user_id' => $importer->id,
            'status' => 'active',
            'created_by' => $importer->id,
        ]);

        $this->actingAs($admin)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertDontSee('data-copy-value="RD3395999"', false)
            ->assertDontSee('data-copyable-identifier="order-id"', false)
            ->assertSee('legacy-imported-order-id', false)
            ->assertSee('Legacy imported order • Imported by Workspace •', false)
            ->assertSee('data-copyable-identifier="serial"', false)
            ->assertSee('data-copy-value="SN999999"', false)
            ->assertSee('data-legacy-verification-mode="imported"', false)
            ->assertSee('Legacy imported order. Verify customer, serial, invoice and eligibility.', false)
            ->assertSee('Verified legacy order details', false);
    }

    public function test_legacy_imported_order_requires_fulfillment_verification_before_assign_ref(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD3396001',
            'serial_number' => 'SN6001',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'legacy_source' => 'radiumbox',
            'legacy_imported_at' => now(),
            'legacy_imported_by_user_id' => $admin->id,
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-IMPORT-001',
            ])
            ->assertSessionHasErrors('transaction_id');

        $this->assertNull($order->fresh()->transaction_id);

        $this->actingAs($admin)
            ->postJson(route('orders.legacy-verification.store', $order), [
                'confirmed' => true,
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'legacy_order.verified_for_fulfillment',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
        ]);

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-IMPORT-001',
            ])
            ->assertRedirect(route('orders.show', $order));

        $this->assertSame('TXN-IMPORT-001', $order->fresh()->transaction_id);
    }

    public function test_dashboard_legacy_imported_order_renders_styled_order_id(): void
    {
        $importer = User::factory()->create(['name' => 'Import Agent']);
        $importer->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD3395988',
            'serial_number' => 'SN3395988',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'legacy_source' => 'radiumbox',
            'legacy_imported_at' => now(),
            'legacy_imported_by_user_id' => $importer->id,
            'status' => 'active',
            'created_by' => $importer->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC3395988',
            'category' => 'General',
            'source' => 'call',
            'title' => 'Legacy dashboard case',
            'description' => 'Imported legacy order.',
            'status' => 'open',
            'created_by' => $importer->id,
            'updated_by' => $importer->id,
        ]);

        $this->actingAs($importer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('legacy-imported-order-id', false)
            ->assertSee('RD3395988', false)
            ->assertSee('Legacy imported order • Imported by Import •', false)
            ->assertSee('data-copyable-identifier="serial"', false)
            ->assertSee('data-copy-value="SN3395988"', false);
    }

    public function test_normal_order_does_not_render_legacy_import_ui_or_require_fulfillment_verification(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'CF-NORMAL-001',
            'serial_number' => 'SN-NORMAL-001',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'cashfree_payment_id' => 'cf_normal_001',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC00090',
            'category' => 'General',
            'source' => 'call',
            'title' => 'Normal support',
            'description' => 'Cashfree order.',
            'status' => 'open',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('incidents.show', $incident))
            ->assertOk()
            ->assertSee(route('orders.show', $order), false)
            ->assertSee('data-copyable-identifier="serial"', false)
            ->assertDontSee('legacy-imported-order-id', false)
            ->assertDontSee('Imported from legacy system by', false);

        $this->actingAs($admin)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('data-requires-legacy-verification="false"', false)
            ->assertDontSee('data-legacy-verification-mode="imported"', false);

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-NORMAL-001',
            ])
            ->assertRedirect(route('orders.show', $order));

        $this->assertSame('TXN-NORMAL-001', $order->fresh()->transaction_id);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'legacy_order.verified_for_fulfillment',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
        ]);
    }
}
