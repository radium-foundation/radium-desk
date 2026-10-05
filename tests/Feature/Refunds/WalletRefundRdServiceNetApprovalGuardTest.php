<?php

namespace Tests\Feature\Refunds;

use App\Enums\ApprovedRefundMethod;
use App\Enums\CustomerPreferredRefundMethod;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Refunds\WalletRefundDestinationResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WalletRefundRdServiceNetApprovalGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rdserviceNetOrderIds(): array
    {
        return [
            'RN order' => ['RN92'],
            'RA order' => ['RA3506948'],
            'RNP order' => ['RNP1'],
        ];
    }

    #[DataProvider('rdserviceNetOrderIds')]
    public function test_rdservice_net_wallet_approval_is_rejected_before_pending_execution(string $orderId): void
    {
        $admin = $this->adminUser();
        $refund = $this->pendingRefund($admin, $orderId);

        $this->actingAs($admin)
            ->post(route('refunds.approve', $refund), [
                'approved_refund_method' => ApprovedRefundMethod::Wallet->value,
            ])
            ->assertSessionHasErrors('approved_refund_method');

        $refund->refresh();

        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertNull($refund->approved_refund_method);
        $this->assertNull($refund->reviewed_at);
    }

    public function test_rdservice_in_wallet_approval_still_reaches_pending_execution(): void
    {
        $admin = $this->adminUser();
        $refund = $this->pendingRefund($admin, 'RD3437801');

        $this->actingAs($admin)
            ->post(route('refunds.approve', $refund), [
                'approved_refund_method' => ApprovedRefundMethod::Wallet->value,
                'deduction_profile_key' => 'custom',
                'cancellation_charges' => 0,
                'gst_on_cancellation' => 0,
                'other_deduction' => 0,
                'refund_amount' => 579,
            ])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-approved');

        $refund->refresh();

        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
        $this->assertSame(ApprovedRefundMethod::Wallet, $refund->approved_refund_method);
    }

    public function test_radiumbox_wallet_approval_still_reaches_pending_execution(): void
    {
        $admin = $this->adminUser();
        $refund = $this->pendingRefund($admin, 'RB403', '917.00', 'REF-67347');

        $this->actingAs($admin)
            ->post(route('refunds.approve', $refund), [
                'approved_refund_method' => ApprovedRefundMethod::Wallet->value,
                'deduction_profile_key' => 'custom',
                'cancellation_charges' => 0,
                'gst_on_cancellation' => 0,
                'other_deduction' => 0,
                'refund_amount' => 917,
            ])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-approved');

        $refund->refresh();

        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
        $this->assertSame(ApprovedRefundMethod::Wallet, $refund->approved_refund_method);
    }

    public function test_rdservice_net_cashfree_approval_remains_available(): void
    {
        $admin = $this->adminUser();
        $refund = $this->pendingRefund($admin, 'RN92', '579.00', 'REF-67329', CustomerPreferredRefundMethod::Opm);

        $this->actingAs($admin)
            ->post(route('refunds.approve', $refund), [
                'approved_refund_method' => ApprovedRefundMethod::Cashfree->value,
                'deduction_profile_key' => 'custom',
                'cancellation_charges' => 0,
                'gst_on_cancellation' => 0,
                'other_deduction' => 0,
                'refund_amount' => 579,
                'review_notes' => 'Route via Cashfree because wallet is unsupported for rdservice.net.',
            ])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-approved');

        $refund->refresh();

        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
        $this->assertSame(ApprovedRefundMethod::Cashfree, $refund->approved_refund_method);
    }

    public function test_rdservice_net_wallet_execution_remains_fail_closed_for_legacy_pending_execution_rows(): void
    {
        Http::fake();

        $ops = User::factory()->create();
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RN92',
            'serial_number' => 'SN-RN92',
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'payment_amount' => '697.00',
            'customer_email' => 'customer@example.com',
            'created_by' => $ops->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-67329',
            'amount' => '579.00',
            'refund_amount' => '579.00',
            'reason' => 'Legacy pending_execution wallet approval on rdservice.net order.',
            'status' => RefundStatus::PendingExecution,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'customer_preferred_method' => CustomerPreferredRefundMethod::Opm,
            'requested_by' => $ops->id,
            'reviewed_by' => $ops->id,
            'reviewed_at' => now(),
            'communication_channels' => [],
        ]);

        $resolver = app(WalletRefundDestinationResolver::class);
        $expectedMessage = $resolver->unsupportedAutomatedWalletCreditMessage('RN92');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'MANUAL-SHOULD-NOT-COUNT',
            ])
            ->assertSessionHasErrors([
                'refund' => $expectedMessage,
            ]);

        $refund->refresh();

        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
        $this->assertNull($refund->executed_at);
        Http::assertNothingSent();
    }

    public function test_pending_execution_wallet_refund_cannot_be_rejected_or_re_routed_to_cashfree(): void
    {
        Http::fake();

        $admin = $this->adminUser();
        $refund = RefundRequest::query()->create([
            'order_id' => $this->createOrder($admin, 'RN92')->id,
            'reference_no' => 'REF-67329-ROUTE',
            'amount' => '579.00',
            'refund_amount' => '579.00',
            'reason' => 'Stuck pending_execution wallet refund cannot be re-routed in Desk.',
            'status' => RefundStatus::PendingExecution,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $admin->id,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'communication_channels' => [],
        ]);

        $this->actingAs($admin)
            ->post(route('refunds.reject', $refund), [
                'review_notes' => 'Attempt to reject after wallet approval.',
            ])
            ->assertSessionHasErrors('refund');

        $refund->refresh();
        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
        $this->assertSame(ApprovedRefundMethod::Wallet, $refund->approved_refund_method);

        $this->actingAs($admin)
            ->post(route('refunds.approve', $refund), [
                'approved_refund_method' => ApprovedRefundMethod::Cashfree->value,
            ])
            ->assertSessionHasErrors('refund');

        $refund->refresh();
        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
        $this->assertSame(ApprovedRefundMethod::Wallet, $refund->approved_refund_method);
        Http::assertNothingSent();
    }

    public function test_wallet_approval_guard_message_identifies_rdservice_net_source(): void
    {
        config([
            'rdservice_net.wallet_refund_credit_enabled' => false,
            'order_lookup.spokes.rdservice_net.enabled' => false,
        ]);

        $resolver = app(WalletRefundDestinationResolver::class);

        try {
            $resolver->assertWalletApprovalAllowed('RN92');
            $this->fail('Expected wallet approval guard to reject rdservice.net orders.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('approved_refund_method', $exception->errors());
            $this->assertSame(
                'No automated wallet-credit destination is configured for rdservice.net orders. Approve this refund using Cashfree or another supported payout method instead.',
                $exception->errors()['approved_refund_method'][0]
            );
        }
    }

    public function test_rdservice_net_wallet_approval_is_allowed_when_spoke_is_configured(): void
    {
        config([
            'rdservice_net.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.rdservice_net.enabled' => true,
            'order_lookup.spokes.rdservice_net.base_url' => 'https://rdservice.net.test',
            'order_lookup.spokes.rdservice_net.token' => 'net-token',
        ]);

        app(WalletRefundDestinationResolver::class)->assertWalletApprovalAllowed('RN92');

        $this->assertTrue(true);
    }

    private function adminUser(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }

    private function createOrder(User $user, string $orderId, ?string $paymentAmount = '1000.00'): Order
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

    private function pendingRefund(
        User $user,
        string $orderId,
        string $amount = '579.00',
        string $referenceNo = 'REF-NET-GUARD',
        ?CustomerPreferredRefundMethod $preferredMethod = null,
    ): RefundRequest {
        $order = $this->createOrder($user, $orderId, $amount);

        return RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => $referenceNo,
            'amount' => $amount,
            'refund_amount' => $amount,
            'reason' => 'Wallet approval guard regression coverage.',
            'status' => RefundStatus::Pending,
            'customer_preferred_method' => $preferredMethod,
            'requested_by' => $user->id,
        ]);
    }
}
