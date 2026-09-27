<?php

namespace Tests\Feature\Refunds;

use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RefundReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RefundExecutionReferenceGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'rdservice_in.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.rdservice_in.enabled' => true,
            'order_lookup.spokes.rdservice_in.base_url' => 'https://rdservice.in.test',
            'order_lookup.spokes.rdservice_in.token' => 'desk-rdservice-wallet-token',
            'radiumbox.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'desk-box-wallet-token',
        ]);
    }

    public function test_store_rejects_manual_reference_no_override(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD9000001',
            'serial_number' => 'SN-9000001',
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => 'active',
            'payment_amount' => '500.00',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->post(route('refunds.store'), [
                'order_id' => $order->id,
                'amount' => '500.00',
                'reason' => 'Customer requested refund for duplicate payment.',
                'remarks' => 'Please process refund request.',
                'customer_preferred_method' => 'opm',
                'reference_no' => 'REF-99999',
            ])
            ->assertSessionHasErrors('reference_no');

        $this->assertDatabaseCount('refund_requests', 0);
    }

    public function test_manual_completion_rejects_desk_refund_reference_as_execution_reference(): void
    {
        $ops = User::factory()->create();
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $refund = $this->pendingManualRefund($ops, 'REF-2026-000272');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'REF-2026-000272',
            ])
            ->assertSessionHasErrors('execution_reference_no');

        $refund->refresh();
        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
        $this->assertNull($refund->execution_reference_no);
    }

    public function test_manual_completion_rejects_desk_refund_reference_as_transaction_id(): void
    {
        $ops = User::factory()->create();
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $refund = $this->pendingManualRefund($ops, 'REF-2026-000450');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_transaction_id' => 'REF-2026-000450',
            ])
            ->assertSessionHasErrors('execution_transaction_id');
    }

    public function test_manual_completion_accepts_external_payout_reference(): void
    {
        $ops = User::factory()->create();
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $refund = $this->pendingManualRefund($ops, 'REF-2026-000451');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR-ABC-123',
                'execution_transaction_id' => 'TXN-90001',
            ])
            ->assertRedirect(route('refunds.show', $refund));

        $refund->refresh();
        $this->assertSame('UTR-ABC-123', $refund->execution_reference_no);
        $this->assertSame('TXN-90001', $refund->execution_transaction_id);
    }

    public function test_wallet_completion_ignores_manual_execution_reference_fields(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'message' => 'Wallet credit created',
                'data' => [
                    'wallet_transaction_id' => 91055,
                    'wallet_reference' => 'RD91055',
                    'desk_refund_reference' => 'REF-2026-000500',
                    'credit' => '617.00',
                    'balance' => '617.00',
                ],
            ], 201),
        ]);

        $ops = User::factory()->create();
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RBX3511999',
            'serial_number' => 'SN-RBX3511999',
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => 'active',
            'payment_amount' => '617.00',
            'customer_email' => 'wallet-guard@example.com',
            'created_by' => $ops->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000500',
            'amount' => '617.00',
            'refund_amount' => '617.00',
            'reason' => 'Wallet refund execution guard test.',
            'status' => RefundStatus::PendingExecution,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $ops->id,
            'reviewed_by' => $ops->id,
            'reviewed_at' => now(),
            'communication_channels' => [],
        ]);

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'REF-2026-000500',
                'execution_transaction_id' => 'REF-2026-000500',
            ])
            ->assertRedirect(route('refunds.show', $refund));

        $refund->refresh();
        $this->assertSame('91055', $refund->execution_transaction_id);
        $this->assertSame('RD91055', $refund->execution_reference_no);
        $this->assertNotSame('REF-2026-000500', $refund->execution_reference_no);
    }

    public function test_new_refund_reference_remains_auto_generated_and_sequential(): void
    {
        $first = app(RefundReferenceService::class)->generate();
        $second = app(RefundReferenceService::class)->generate();

        $this->assertSame('REF-67315', $first);
        $this->assertSame('REF-67316', $second);
        $this->assertNotSame($first, $second);
    }

    public function test_create_form_shows_next_refund_reference_preview(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('refunds.create'))
            ->assertOk()
            ->assertSee('REF-67315', false);
    }

    private function pendingManualRefund(User $ops, string $referenceNo): RefundRequest
    {
        $order = Order::query()->create([
            'order_id' => 'RD-'.$referenceNo,
            'serial_number' => 'SN-'.$referenceNo,
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => 'active',
            'payment_amount' => '1000.00',
            'created_by' => $ops->id,
        ]);

        return RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => $referenceNo,
            'amount' => '1000.00',
            'refund_amount' => '1000.00',
            'reason' => 'Manual execution reference guard test.',
            'status' => RefundStatus::PendingExecution,
            'approved_refund_method' => ApprovedRefundMethod::BankTransfer,
            'requested_by' => $ops->id,
            'reviewed_by' => $ops->id,
            'reviewed_at' => now(),
            'communication_channels' => [],
        ]);
    }
}
