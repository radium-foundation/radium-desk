<?php

namespace Tests\Feature\Refunds;

use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundRevokeCustomerOutcome;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RdService\RdServiceNetWalletRefundReversalClient;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RefundRevokeRdServiceNetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['commercial_state.enabled' => true]);
        config([
            'rdservice_net.wallet_refund_reversal_enabled' => true,
            'order_lookup.spokes.rdservice_net.enabled' => true,
            'order_lookup.spokes.rdservice_net.base_url' => 'https://rdservice.net.test',
            'order_lookup.spokes.rdservice_net.token' => 'desk-rdservice-net-wallet-token',
        ]);
    }

    public function test_eligible_rdnet_closed_wallet_refund_revokes_via_rdnet_client(): void
    {
        Http::fake([
            'https://rdservice.net.test/api/integrations/v1/wallet-refund-reversals' => Http::response([
                'message' => 'Central Wallet reversal created',
                'data' => [
                    'wallet_reversal_transaction_id' => 802,
                    'wallet_reversal_reference' => 'CW-R:802',
                    'desk_refund_reference' => 'REF-2026-RDNET-01',
                    'debit' => '499.00',
                    'balance' => '0.00',
                ],
            ], 201),
        ]);

        [$admin, $refund] = $this->completedWalletRefundFixture(
            orderNumber: 'RN200',
            reference: 'REF-2026-RDNET-01',
            walletTransactionId: '801',
        );

        $this->actingAs($admin)
            ->post(route('refunds.revoke', $refund), [
                'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
                'revoke_reason' => 'Customer wants service instead of wallet refund.',
            ])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-revoked');

        $refund->refresh();
        $this->assertSame(RefundStatus::Revoked, $refund->status);
        $this->assertSame('CW-R:802', $refund->revoke_wallet_reversal_reference);
        $this->assertSame('802', $refund->revoke_wallet_reversal_transaction_id);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), RdServiceNetWalletRefundReversalClient::REVERSAL_PATH)
                && $request['order_id'] === 'RN200';
        });
    }

    public function test_ref_67354_protected_refund_fails_closed_when_reversal_flag_disabled(): void
    {
        config(['rdservice_net.wallet_refund_reversal_enabled' => false]);

        Http::fake();

        [$admin, $refund] = $this->completedWalletRefundFixture(
            orderNumber: 'RN153',
            amount: '731.00',
            reference: 'REF-67354',
            walletTransactionId: '72',
        );

        $this->actingAs($admin)
            ->post(route('refunds.revoke', $refund), [
                'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
                'revoke_reason' => 'Must not reverse protected refund while flag is off.',
            ])
            ->assertSessionHasErrors('refund');

        $refund->refresh();
        $this->assertSame(RefundStatus::Closed, $refund->status);
        $this->assertNull($refund->revoked_at);

        Http::assertNothingSent();
    }

    public function test_ref_67366_protected_refund_uses_mocked_rdnet_endpoint_only(): void
    {
        Http::fake([
            'https://rdservice.net.test/api/integrations/v1/wallet-refund-reversals' => Http::response([
                'message' => 'Central Wallet reversal created',
                'data' => [
                    'wallet_reversal_transaction_id' => 803,
                    'wallet_reversal_reference' => 'CW-R:803',
                    'desk_refund_reference' => 'REF-67366',
                    'debit' => '599.00',
                    'balance' => '0.00',
                ],
            ], 201),
        ]);

        [$admin, $refund] = $this->completedWalletRefundFixture(
            orderNumber: 'RN158',
            amount: '599.00',
            reference: 'REF-67366',
            walletTransactionId: '71',
        );

        $this->actingAs($admin)
            ->post(route('refunds.revoke', $refund), [
                'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
                'revoke_reason' => 'Test revoke path only — mocked HTTP.',
            ])
            ->assertRedirect(route('refunds.show', $refund));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://rdservice.net.test'.RdServiceNetWalletRefundReversalClient::REVERSAL_PATH
                && $request['desk_refund_reference'] === 'REF-67366'
                && $request['order_id'] === 'RN158'
                && $request['original_wallet_transaction_id'] === '71';
        });

        $refund->refresh();
        $this->assertSame(RefundStatus::Revoked, $refund->status);
        $this->assertSame('803', $refund->revoke_wallet_reversal_transaction_id);
    }

    /**
     * @return array{0: User, 1: RefundRequest}
     */
    private function completedWalletRefundFixture(
        string $orderNumber,
        string $amount = '499.00',
        string $reference = 'REF-2026-RDNET-01',
        string $walletTransactionId = '801',
    ): array {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $order = Order::query()->create([
            'order_id' => $orderNumber,
            'serial_number' => 'SN-'.$orderNumber,
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'payment_amount' => $amount,
            'customer_email' => 'customer@example.com',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'order_record_id' => $order->id,
            'reference_no' => 'SC-'.substr($reference, -6),
            'category' => 'Refund',
            'source' => 'internal',
            'title' => 'Refund revoke test',
            'description' => 'Refund revoke test incident.',
            'status' => 'open',
            'created_by' => $admin->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => $reference,
            'amount' => $amount,
            'refund_amount' => $amount,
            'reason' => 'Wallet refund revoke test.',
            'status' => RefundStatus::Closed,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $admin->id,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now()->subDay(),
            'executed_by' => $admin->id,
            'executed_at' => now()->subDay(),
            'closed_at' => now()->subDay(),
            'execution_reference_no' => 'CW:'.$walletTransactionId,
            'execution_transaction_id' => $walletTransactionId,
            'communication_channels' => [],
        ]);

        return [$admin, $refund];
    }
}
