<?php

namespace Tests\Feature\Refunds;

use App\Enums\ApprovedRefundMethod;
use App\Enums\CustomerPreferredRefundMethod;
use App\Enums\RefundStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Refunds\ManualRefundExecutor;
use App\Services\Refunds\RefundExecutorResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RefundExecutionMethodRerouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_pending_execution_wallet_on_rdservice_net_can_be_rerouted_to_cashfree(): void
    {
        Http::fake();

        [$admin, $refund] = $this->stuckWalletRefundFixture();

        $this->actingAs($admin)
            ->post(route('refunds.reroute-execution-method', $refund), [
                'reroute_reason' => 'Customer prefers original Cashfree payment; wallet unsupported for rdservice.net.',
            ])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-execution-method-rerouted');

        $refund->refresh();

        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
        $this->assertSame(ApprovedRefundMethod::Cashfree, $refund->approved_refund_method);
        $this->assertSame('579.00', (string) $refund->refund_amount);
        $this->assertNull($refund->executed_at);

        Http::assertNothingSent();
    }

    public function test_reroute_writes_audit_history_with_old_and_new_methods(): void
    {
        Http::fake();

        [$admin, $refund] = $this->stuckWalletRefundFixture();

        $this->actingAs($admin)
            ->post(route('refunds.reroute-execution-method', $refund), [
                'reroute_reason' => 'Route REF-67329 to Cashfree after wallet approval guard.',
            ]);

        $audit = AuditLog::query()
            ->where('event', 'refund.execution_method_rerouted')
            ->where('auditable_id', $refund->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('wallet', $audit->old_values['approved_refund_method'] ?? null);
        $this->assertSame('cashfree', $audit->new_values['approved_refund_method'] ?? null);
        $this->assertSame('RN92', $audit->new_values['order_id'] ?? null);
        $this->assertSame('REF-67329', $audit->new_values['reference_no'] ?? null);
        $this->assertSame('CF-PAY-67329', $audit->new_values['original_payment_reference'] ?? null);
        $this->assertSame('opm', $audit->new_values['customer_preferred_method'] ?? null);
        $this->assertSame($admin->id, $audit->user_id);
    }

    public function test_reroute_does_not_change_order_payment_state(): void
    {
        Http::fake();

        [$admin, $refund] = $this->stuckWalletRefundFixture();
        $order = $refund->order;
        $before = $order->only([
            'cashfree_payment_id',
            'payment_amount',
            'gateway_payment_id',
            'transaction_id',
            'bank_reference',
        ]);

        $this->actingAs($admin)
            ->post(route('refunds.reroute-execution-method', $refund), [
                'reroute_reason' => 'Preserve payment evidence while changing payout method.',
            ]);

        $order->refresh();

        $this->assertSame($before, $order->only([
            'cashfree_payment_id',
            'payment_amount',
            'gateway_payment_id',
            'transaction_id',
            'bank_reference',
        ]));
    }

    public function test_second_reroute_is_idempotent(): void
    {
        Http::fake();

        [$admin, $refund] = $this->stuckWalletRefundFixture();
        $payload = [
            'reroute_reason' => 'First re-route to Cashfree.',
        ];

        $this->actingAs($admin)
            ->post(route('refunds.reroute-execution-method', $refund), $payload)
            ->assertSessionHas('status', 'refund-execution-method-rerouted');

        $refund->refresh();
        $this->assertSame(ApprovedRefundMethod::Cashfree, $refund->approved_refund_method);

        $this->actingAs($admin)
            ->post(route('refunds.reroute-execution-method', $refund), [
                'reroute_reason' => 'Second attempt should be a no-op.',
            ])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-execution-method-rerouted');

        $this->assertSame(1, AuditLog::query()
            ->where('event', 'refund.execution_method_rerouted')
            ->where('auditable_id', $refund->id)
            ->count());

        Http::assertNothingSent();
    }

    public function test_completed_refund_cannot_be_rerouted(): void
    {
        Http::fake();

        [$admin, $refund] = $this->stuckWalletRefundFixture();
        $refund->update([
            'status' => RefundStatus::Completed,
            'executed_at' => now(),
            'executed_by' => $admin->id,
            'execution_reference_no' => 'UTR-1',
        ]);

        $this->actingAs($admin)
            ->post(route('refunds.reroute-execution-method', $refund), [
                'reroute_reason' => 'Should not work on completed refunds.',
            ])
            ->assertSessionHasErrors('refund');
    }

    public function test_rejected_refund_cannot_be_rerouted(): void
    {
        Http::fake();

        [$admin, $refund] = $this->stuckWalletRefundFixture();
        $refund->update([
            'status' => RefundStatus::Rejected,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
        ]);

        $this->actingAs($admin)
            ->post(route('refunds.reroute-execution-method', $refund), [
                'reroute_reason' => 'Should not work on rejected refunds.',
            ])
            ->assertSessionHasErrors('refund');
    }

    public function test_revoked_refund_cannot_be_rerouted(): void
    {
        Http::fake();

        [$admin, $refund] = $this->stuckWalletRefundFixture();
        $refund->update([
            'status' => RefundStatus::Revoked,
            'executed_at' => now(),
            'revoked_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('refunds.reroute-execution-method', $refund), [
                'reroute_reason' => 'Should not work on revoked refunds.',
            ])
            ->assertSessionHasErrors('refund');
    }

    public function test_pending_approval_refund_cannot_be_rerouted(): void
    {
        Http::fake();

        [$admin, $refund] = $this->stuckWalletRefundFixture();
        $refund->update([
            'status' => RefundStatus::Pending,
            'approved_refund_method' => null,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ]);

        $this->actingAs($admin)
            ->post(route('refunds.reroute-execution-method', $refund), [
                'reroute_reason' => 'Should not work before approval.',
            ])
            ->assertSessionHasErrors('refund');
    }

    public function test_supported_wallet_destination_cannot_be_rerouted(): void
    {
        Http::fake();

        $admin = $this->adminUser();
        $order = $this->createOrder($admin, 'RB403', '917.00', 'CF-PAY-67347');
        $refund = $this->createRefund($admin, $order, 'REF-67347');

        $this->actingAs($admin)
            ->post(route('refunds.reroute-execution-method', $refund), [
                'reroute_reason' => 'Radbox wallet is supported; re-route should be blocked.',
            ])
            ->assertSessionHasErrors('refund');

        $refund->refresh();
        $this->assertSame(ApprovedRefundMethod::Wallet, $refund->approved_refund_method);
    }

    public function test_reroute_requires_original_payment_evidence(): void
    {
        Http::fake();

        $admin = $this->adminUser();
        $order = $this->createOrder($admin, 'RN93', '579.00', paymentReference: null);
        $refund = $this->createRefund($admin, $order, 'REF-NO-PAY');

        $this->actingAs($admin)
            ->post(route('refunds.reroute-execution-method', $refund), [
                'reroute_reason' => 'Missing payment evidence should block re-route.',
            ])
            ->assertSessionHasErrors('refund');
    }

    public function test_after_reroute_complete_resolves_manual_executor(): void
    {
        Http::fake();

        [$admin, $refund] = $this->stuckWalletRefundFixture();

        $this->actingAs($admin)
            ->post(route('refunds.reroute-execution-method', $refund), [
                'reroute_reason' => 'Prepare for manual Cashfree completion.',
            ]);

        $refund->refresh();

        $resolver = app(RefundExecutorResolver::class);
        $executor = $resolver->for(ApprovedRefundMethod::Cashfree);

        $this->assertInstanceOf(ManualRefundExecutor::class, $executor);

        $this->actingAs($admin)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR-REROUTE-67329',
                'execution_transaction_id' => 'TXN-REROUTE-67329',
            ])
            ->assertRedirect(route('refunds.show', $refund));

        Http::assertNothingSent();
    }

    public function test_rdservice_net_wallet_approval_guard_remains_blocked(): void
    {
        $admin = $this->adminUser();
        $order = $this->createOrder($admin, 'RN94', '579.00', 'CF-PAY-94');
        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-NET-GUARD',
            'amount' => '579.00',
            'refund_amount' => '579.00',
            'reason' => 'Guard regression after re-route feature.',
            'status' => RefundStatus::Pending,
            'requested_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('refunds.approve', $refund), [
                'approved_refund_method' => ApprovedRefundMethod::Wallet->value,
            ])
            ->assertSessionHasErrors('approved_refund_method');

        $refund->refresh();
        $this->assertSame(RefundStatus::Pending, $refund->status);
    }

    public function test_unauthorized_user_cannot_reroute(): void
    {
        Http::fake();

        [$admin, $refund] = $this->stuckWalletRefundFixture();
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->post(route('refunds.reroute-execution-method', $refund), [
                'reroute_reason' => 'Agents should not re-route payout methods.',
            ])
            ->assertForbidden();

        $refund->refresh();
        $this->assertSame(ApprovedRefundMethod::Wallet, $refund->approved_refund_method);
    }

    /**
     * @return array{0: User, 1: RefundRequest}
     */
    private function stuckWalletRefundFixture(): array
    {
        $admin = $this->adminUser();
        $order = $this->createOrder($admin, 'RN92', '697.00', 'CF-PAY-67329');
        $refund = $this->createRefund($admin, $order, 'REF-67329');

        return [$admin, $refund];
    }

    private function adminUser(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }

    private function createOrder(
        User $user,
        string $orderId,
        string $paymentAmount,
        ?string $paymentReference = 'CF-PAY-DEFAULT',
    ): Order {
        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'payment_amount' => $paymentAmount,
            'cashfree_payment_id' => $paymentReference,
            'customer_email' => 'customer@example.com',
            'created_by' => $user->id,
        ]);
    }

    private function createRefund(User $user, Order $order, string $referenceNo): RefundRequest
    {
        return RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => $referenceNo,
            'amount' => '579.00',
            'refund_amount' => '579.00',
            'reason' => 'Legacy wallet approval on unsupported rdservice.net order.',
            'status' => RefundStatus::PendingExecution,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'customer_preferred_method' => CustomerPreferredRefundMethod::Opm,
            'requested_by' => $user->id,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'communication_channels' => [],
        ]);
    }
}
