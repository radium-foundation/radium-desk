<?php

namespace Tests\Feature;

use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WalletRefundExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'radiumbox.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'desk-wallet-token',
            'order_lookup.spokes.radiumbox_com.connect_timeout_seconds' => 3,
            'order_lookup.spokes.radiumbox_com.timeout_seconds' => 8,
        ]);
    }

    public function test_wallet_refund_completion_posts_automated_wallet_credit(): void
    {
        Http::fake([
            '*/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'message' => 'Wallet credit created',
                'data' => [
                    'wallet_transaction_id' => 273105,
                    'wallet_reference' => 'RD273105',
                    'desk_refund_reference' => 'REF-2026-000820',
                    'userid' => 42,
                    'credit' => 617,
                    'balance' => 617,
                ],
            ], 201),
        ]);

        [$ops, $refund] = $this->pendingWalletRefundFixture('RD3454444', 617);

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-completed');

        $refund->refresh();
        $this->assertContains($refund->status, [RefundStatus::Completed, RefundStatus::Closed]);
        $this->assertSame('RD273105', $refund->execution_reference_no);
        $this->assertSame('273105', $refund->execution_transaction_id);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://radiumbox.test/api/integrations/v1/wallet-refunds'
                && $request['desk_refund_reference'] === 'REF-2026-000820'
                && $request['order_id'] === 'RD3454444'
                && (float) $request['amount'] === 617.0;
        });
    }

    public function test_wallet_refund_falls_back_to_manual_when_integration_disabled(): void
    {
        config(['radiumbox.wallet_refund_credit_enabled' => false]);
        Http::fake();

        [$ops, $refund] = $this->pendingWalletRefundFixture('RD3454445', 500);

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'RD273106',
            ])
            ->assertRedirect(route('refunds.show', $refund));

        $refund->refresh();
        $this->assertSame('RD273106', $refund->execution_reference_no);
        Http::assertNothingSent();
    }

    public function test_non_wallet_refund_does_not_call_wallet_credit_api(): void
    {
        Http::fake();

        $ops = User::factory()->create();
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);
        $order = $this->createOrder($ops, 'RD3454446', 900);
        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000821',
            'amount' => 900,
            'refund_amount' => 900,
            'reason' => 'Bank transfer refund should not hit wallet API.',
            'status' => RefundStatus::PendingExecution,
            'approved_refund_method' => ApprovedRefundMethod::BankTransfer,
            'requested_by' => $ops->id,
            'reviewed_by' => $ops->id,
            'reviewed_at' => now(),
            'communication_channels' => [],
        ]);

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR-123',
            ])
            ->assertRedirect(route('refunds.show', $refund));

        Http::assertNothingSent();
    }

    public function test_pending_refund_cannot_be_completed_without_execution_state(): void
    {
        Http::fake();

        $ops = User::factory()->create();
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);
        $order = $this->createOrder($ops, 'RD3454447', 400);
        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000822',
            'amount' => 400,
            'refund_amount' => 400,
            'reason' => 'Pending approval should not credit wallet.',
            'status' => RefundStatus::Pending,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $ops->id,
            'communication_channels' => [],
        ]);

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertSessionHasErrors('refund');

        Http::assertNothingSent();
    }

    /**
     * @return array{0: User, 1: RefundRequest}
     */
    private function pendingWalletRefundFixture(string $orderId, float $amount): array
    {
        $ops = User::factory()->create();
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $order = $this->createOrder($ops, $orderId, $amount);
        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-2026-000820',
            'category' => 'Refund',
            'source' => 'internal',
            'title' => 'Wallet refund execution test',
            'description' => 'Wallet refund execution test incident.',
            'status' => 'open',
            'created_by' => $ops->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => 'REF-2026-000820',
            'amount' => $amount,
            'refund_amount' => $amount,
            'reason' => 'Wallet refund execution test.',
            'status' => RefundStatus::PendingExecution,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $ops->id,
            'reviewed_by' => $ops->id,
            'reviewed_at' => now(),
            'communication_channels' => [],
        ]);

        return [$ops, $refund];
    }

    private function createOrder(User $user, string $orderId, float $paymentAmount): Order
    {
        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'payment_amount' => $paymentAmount,
            'customer_email' => 'customer@example.com',
            'created_by' => $user->id,
        ]);
    }
}
