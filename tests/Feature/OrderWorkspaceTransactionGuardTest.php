<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\QuickServiceRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderWorkspaceTransactionGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_order_workspace_shows_legacy_verification_guard_for_legacy_orders(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-WS-LEGACY-1',
            'serial_number' => 'SN-WS-LEGACY-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => OrderStatus::Active,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('data-order-workspace-transaction-form="true"', false)
            ->assertSee('data-requires-legacy-verification="true"', false)
            ->assertSee(route('orders.legacy-verification.store', $order), false)
            ->assertSee('Legacy Customer Verification', false);
    }

    public function test_order_workspace_blocks_unverified_inquiry_completion(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'INQ-SC00099',
            'customer_phone' => '9000000099',
            'status' => OrderStatus::Active,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Customer verification required before completing service.')
            ->assertDontSee('data-order-workspace-transaction-form="true"', false);

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-WS-INQ-BLOCK',
            ])
            ->assertSessionHasErrors('transaction_id');
    }

    public function test_order_workspace_allows_legacy_completion_after_verification(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-WS-LEGACY-2',
            'serial_number' => 'SN-WS-LEGACY-2',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => OrderStatus::Active,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->postJson(route('orders.legacy-verification.store', $order), [
                'confirmed' => true,
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->from(route('orders.show', $order))
            ->post(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-WS-LEGACY-2',
            ])
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('status', 'order-transaction-assigned');

        $this->assertSame('TXN-WS-LEGACY-2', $order->fresh()->transaction_id);
    }

    public function test_order_workspace_cashfree_customer_can_assign_without_legacy_verification(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'CF-WS-001',
            'serial_number' => 'SN-WS-CF-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'cashfree_payment_id' => 'cf_ws_001',
            'status' => OrderStatus::Active,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('data-order-workspace-transaction-form="true"', false)
            ->assertSee('data-requires-legacy-verification="false"', false);

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-WS-CF-1',
            ])
            ->assertRedirect(route('orders.show', $order));

        $this->assertSame('TXN-WS-CF-1', $order->fresh()->transaction_id);
    }

    public function test_quick_service_create_blocks_phantom_manual_order_without_context(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(QuickServiceRequestService::class)->create(
            user: $agent,
            orderId: 'RD-PHANTOM-001',
            serialNumber: '7881999',
            product: 'MFS 110',
            source: IncidentSource::Call,
            notes: 'Should be blocked.',
        );
    }

    public function test_quick_service_create_blocks_rd_style_order_even_with_internal_context(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(QuickServiceRequestService::class)->create(
            user: $agent,
            orderId: 'RD-PHANTOM-002',
            serialNumber: '7881998',
            product: 'MFS 110',
            source: IncidentSource::Call,
            notes: 'Should be blocked.',
            allowManualOrderIdentityCreation: true,
        );
    }
}
