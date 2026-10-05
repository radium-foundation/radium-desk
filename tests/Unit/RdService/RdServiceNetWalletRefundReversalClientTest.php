<?php

namespace Tests\Unit\RdService;

use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RdService\RdServiceNetWalletRefundReversalClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RdServiceNetWalletRefundReversalClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rdservice_net.wallet_refund_reversal_enabled' => true,
            'order_lookup.spokes.rdservice_net.enabled' => true,
            'order_lookup.spokes.rdservice_net.base_url' => 'https://rdservice.net.test',
            'order_lookup.spokes.rdservice_net.token' => 'desk-rdservice-net-wallet-token',
        ]);
    }

    public function test_is_not_configured_when_feature_flag_is_off(): void
    {
        config(['rdservice_net.wallet_refund_reversal_enabled' => false]);

        $this->assertFalse(app(RdServiceNetWalletRefundReversalClient::class)->isConfigured());
    }

    public function test_disabled_flag_prevents_outbound_request(): void
    {
        config(['rdservice_net.wallet_refund_reversal_enabled' => false]);

        Http::fake();

        $refund = $this->makeRefund('REF-67366', '72');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('rdservice.net wallet refund reversal is not configured');

        app(RdServiceNetWalletRefundReversalClient::class)->reverseWalletRefund(
            refund: $refund,
            orderId: 'RN158',
            amount: '599.00',
            idempotencyKey: 'refund-revoke:67366',
        );

        Http::assertNothingSent();
    }

    public function test_reversal_posts_to_rdnet_endpoint_with_expected_payload(): void
    {
        Http::fake([
            'https://rdservice.net.test/api/integrations/v1/wallet-refund-reversals' => Http::response([
                'message' => 'Central Wallet reversal created',
                'data' => [
                    'wallet_reversal_transaction_id' => 73,
                    'wallet_reversal_reference' => 'CW-R:73',
                    'desk_refund_reference' => 'REF-67366',
                    'debit' => '599.00',
                    'balance' => '0.00',
                ],
            ], 201),
        ]);

        $refund = $this->makeRefund('REF-67366', '71');

        $result = app(RdServiceNetWalletRefundReversalClient::class)->reverseWalletRefund(
            refund: $refund,
            orderId: 'RN158',
            amount: '599.00',
            idempotencyKey: 'refund-revoke:67366',
            customerEmail: 'customer@example.com',
        );

        $this->assertSame('CW-R:73', $result['wallet_reversal_reference']);
        $this->assertSame('73', $result['wallet_reversal_transaction_id']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://rdservice.net.test'.RdServiceNetWalletRefundReversalClient::REVERSAL_PATH
                && $request->hasHeader('Authorization', 'Bearer desk-rdservice-net-wallet-token')
                && $request['source_system'] === 'radium_desk'
                && $request['desk_refund_reference'] === 'REF-67366'
                && $request['order_id'] === 'RN158'
                && $request['amount'] === '599.00'
                && $request['currency'] === 'INR'
                && $request['idempotency_key'] === 'refund-revoke:67366'
                && $request['original_wallet_transaction_id'] === '71'
                && $request['customer_email'] === 'customer@example.com';
        });
    }

    private function makeRefund(string $reference, string $executionTransactionId): RefundRequest
    {
        $admin = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'RN158',
            'serial_number' => 'SN-RN158',
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => 'active',
            'payment_amount' => '599.00',
            'customer_email' => 'customer@example.com',
            'created_by' => $admin->id,
        ]);

        return RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => $reference,
            'amount' => '599.00',
            'refund_amount' => '599.00',
            'reason' => 'Test',
            'status' => RefundStatus::Closed,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $admin->id,
            'execution_transaction_id' => $executionTransactionId,
            'communication_channels' => [],
        ]);
    }
}
