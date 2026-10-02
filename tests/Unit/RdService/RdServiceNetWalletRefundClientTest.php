<?php

namespace Tests\Unit\RdService;

use App\Services\RdService\RdServiceNetWalletRefundClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RdServiceNetWalletRefundClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rdservice_net.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.rdservice_net.enabled' => true,
            'order_lookup.spokes.rdservice_net.base_url' => 'https://rdservice.net.test',
            'order_lookup.spokes.rdservice_net.token' => 'desk-rdservice-net-wallet-token',
            'order_lookup.spokes.rdservice_net.connect_timeout_seconds' => 3,
            'order_lookup.spokes.rdservice_net.timeout_seconds' => 8,
        ]);
    }

    public function test_is_not_configured_when_feature_flag_is_off(): void
    {
        config(['rdservice_net.wallet_refund_credit_enabled' => false]);

        $this->assertFalse(app(RdServiceNetWalletRefundClient::class)->isConfigured());
    }

    public function test_accepts_central_wallet_ledger_reference_from_production_shape(): void
    {
        Http::fake([
            'https://rdservice.net.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'message' => 'Central Wallet credit created',
                'data' => [
                    'wallet_transaction_id' => 56,
                    'wallet_reference' => '50ff2e87-1111-2222-3333-444444444444',
                    'source_system' => 'radium_desk',
                    'desk_refund_reference' => 'REF-67366',
                    'credit' => '599.00',
                    'currency' => 'INR',
                    'balance' => '599.00',
                ],
            ], 201),
        ]);

        $result = app(RdServiceNetWalletRefundClient::class)->creditWalletRefund(
            deskRefundReference: 'REF-67366',
            orderId: 'RN158',
            amount: '599.00',
        );

        $this->assertSame('50ff2e87-1111-2222-3333-444444444444', $result['wallet_reference']);
        $this->assertSame(56, $result['wallet_transaction_id']);
        $this->assertSame('599.00', $result['balance']);
    }

    public function test_rejects_credit_amount_mismatch(): void
    {
        $this->expectWalletCreditDetailsValidationError([
            'wallet_reference' => '50ff2e87-1111-2222-3333-444444444444',
            'wallet_transaction_id' => 56,
            'credit' => '100.00',
            'currency' => 'INR',
        ]);
    }

    public function test_rejects_currency_mismatch(): void
    {
        $this->expectWalletCreditDetailsValidationError([
            'wallet_reference' => '50ff2e87-1111-2222-3333-444444444444',
            'wallet_transaction_id' => 56,
            'credit' => '599.00',
            'currency' => 'USD',
        ]);
    }

    public function test_rejects_missing_wallet_reference(): void
    {
        $this->expectWalletCreditDetailsValidationError([
            'wallet_transaction_id' => 56,
            'credit' => '599.00',
            'currency' => 'INR',
        ]);
    }

    public function test_surfaces_spoke_validation_message_on_http_422(): void
    {
        Http::fake([
            'https://rdservice.net.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 422,
                'message' => 'No trusted Central Wallet account link exists for this customer.',
            ], 422),
        ]);

        try {
            app(RdServiceNetWalletRefundClient::class)->creditWalletRefund(
                deskRefundReference: 'REF-67366',
                orderId: 'RN158',
                amount: '599.00',
            );
            $this->fail('Expected spoke validation failure.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'No trusted Central Wallet account link exists for this customer.',
                $exception->errors()['refund'][0] ?? null,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function expectWalletCreditDetailsValidationError(array $data): void
    {
        Http::fake([
            'https://rdservice.net.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'message' => 'Central Wallet credit created',
                'data' => array_merge([
                    'source_system' => 'radium_desk',
                    'desk_refund_reference' => 'REF-67366',
                ], $data),
            ], 201),
        ]);

        try {
            app(RdServiceNetWalletRefundClient::class)->creditWalletRefund(
                deskRefundReference: 'REF-67366',
                orderId: 'RN158',
                amount: '599.00',
            );
            $this->fail('Expected wallet credit details validation failure.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'rdservice.net wallet refund API did not return wallet credit details.',
                $exception->errors()['refund'][0] ?? null,
            );
        }
    }
}
