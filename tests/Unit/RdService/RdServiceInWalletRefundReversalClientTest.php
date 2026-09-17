<?php

namespace Tests\Unit\RdService;

use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RdService\RdServiceInWalletRefundReversalClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RdServiceInWalletRefundReversalClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rdservice_in.wallet_refund_reversal_enabled' => true,
            'order_lookup.spokes.rdservice_in.enabled' => true,
            'order_lookup.spokes.rdservice_in.base_url' => 'https://rdservice.in.test',
            'order_lookup.spokes.rdservice_in.token' => 'desk-rdservice-wallet-token',
        ]);
    }

    public function test_reversal_posts_idempotency_key_and_correlates_response(): void
    {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refund-reversals' => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_reversal_transaction_id' => 2559,
                    'wallet_reversal_reference' => '2559',
                    'desk_refund_reference' => 'REF-2026-000296',
                    'debit' => '499.00',
                    'balance' => '0.00',
                ],
            ], 201),
        ]);

        $admin = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'RD3147',
            'serial_number' => 'SN-RD3147',
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => 'active',
            'payment_amount' => '499.00',
            'customer_email' => 'customer@example.com',
            'created_by' => $admin->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000296',
            'amount' => '499.00',
            'refund_amount' => '499.00',
            'reason' => 'Test',
            'status' => RefundStatus::Closed,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $admin->id,
            'execution_transaction_id' => '2559',
            'communication_channels' => [],
        ]);

        $result = app(RdServiceInWalletRefundReversalClient::class)->reverseWalletRefund(
            refund: $refund,
            orderId: 'RD3147',
            amount: '499.00',
            idempotencyKey: 'refund-revoke:296',
            customerEmail: 'customer@example.com',
        );

        $this->assertSame('2559', $result['wallet_reversal_reference']);
        $this->assertSame('2559', $result['wallet_reversal_transaction_id']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://rdservice.in.test'.RdServiceInWalletRefundReversalClient::REVERSAL_PATH
                && $request['desk_refund_reference'] === 'REF-2026-000296'
                && $request['order_id'] === 'RD3147'
                && $request['amount'] === '499.00'
                && $request['idempotency_key'] === 'refund-revoke:296'
                && $request['original_wallet_transaction_id'] === '2559';
        });
    }
}
