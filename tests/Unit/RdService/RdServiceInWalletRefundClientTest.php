<?php

namespace Tests\Unit\RdService;

use App\Services\RdService\RdServiceInWalletRefundClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RdServiceInWalletRefundClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rdservice_in.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.rdservice_in.enabled' => true,
            'order_lookup.spokes.rdservice_in.base_url' => 'https://rdservice.in.test',
            'order_lookup.spokes.rdservice_in.token' => 'desk-rdservice-wallet-token',
            'order_lookup.spokes.rdservice_in.connect_timeout_seconds' => 3,
            'order_lookup.spokes.rdservice_in.timeout_seconds' => 8,
        ]);
    }

    public function test_accepts_numeric_json_wallet_reference_from_production_shape(): void
    {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'message' => 'Wallet credit created',
                'data' => [
                    'wallet_transaction_id' => 2563,
                    'wallet_reference' => 2563,
                    'source_system' => 'radium_desk',
                    'desk_refund_reference' => 'REF-2026-000305',
                    'credit' => '497.00',
                    'currency' => 'INR',
                    'balance' => '497.00',
                ],
            ], 201),
        ]);

        $result = app(RdServiceInWalletRefundClient::class)->creditWalletRefund(
            deskRefundReference: 'REF-2026-000305',
            orderId: 'RD4328',
            amount: '497.00',
        );

        $this->assertSame('2563', $result['wallet_reference']);
        $this->assertSame(2563, $result['wallet_transaction_id']);
        $this->assertSame('497.00', $result['balance']);
    }

    public function test_accepts_string_wallet_reference(): void
    {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'message' => 'Wallet credit created',
                'data' => [
                    'wallet_transaction_id' => 91001,
                    'wallet_reference' => '2563',
                    'source_system' => 'radium_desk',
                    'desk_refund_reference' => 'REF-2026-009920',
                    'credit' => '499.00',
                    'currency' => 'INR',
                    'balance' => '499.00',
                ],
            ], 201),
        ]);

        $result = app(RdServiceInWalletRefundClient::class)->creditWalletRefund(
            deskRefundReference: 'REF-2026-009920',
            orderId: 'RD3437801',
            amount: '499.00',
        );

        $this->assertSame('2563', $result['wallet_reference']);
        $this->assertSame(91001, $result['wallet_transaction_id']);
    }

    public function test_rejects_missing_wallet_reference(): void
    {
        $this->expectWalletCreditDetailsValidationError([
            'wallet_transaction_id' => 2563,
            'credit' => '497.00',
            'currency' => 'INR',
        ]);
    }

    public function test_rejects_null_wallet_reference(): void
    {
        $this->expectWalletCreditDetailsValidationError([
            'wallet_reference' => null,
            'wallet_transaction_id' => 2563,
            'credit' => '497.00',
            'currency' => 'INR',
        ]);
    }

    public function test_rejects_invalid_wallet_reference(): void
    {
        $this->expectWalletCreditDetailsValidationError([
            'wallet_reference' => ['bad'],
            'wallet_transaction_id' => 2563,
            'credit' => '497.00',
            'currency' => 'INR',
        ]);
    }

    public function test_rejects_invalid_wallet_transaction_id(): void
    {
        $this->expectWalletCreditDetailsValidationError([
            'wallet_reference' => 2563,
            'wallet_transaction_id' => 'not-a-number',
            'credit' => '497.00',
            'currency' => 'INR',
        ]);
    }

    public function test_rejects_credit_amount_mismatch(): void
    {
        $this->expectWalletCreditDetailsValidationError([
            'wallet_reference' => 2563,
            'wallet_transaction_id' => 2563,
            'credit' => '100.00',
            'currency' => 'INR',
        ]);
    }

    public function test_rejects_currency_mismatch(): void
    {
        $this->expectWalletCreditDetailsValidationError([
            'wallet_reference' => 2563,
            'wallet_transaction_id' => 2563,
            'credit' => '497.00',
            'currency' => 'USD',
        ]);
    }

    public function test_rejects_malformed_response_envelope(): void
    {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'message' => 'Wallet credit created',
                'data' => 'not-an-array',
            ], 201),
        ]);

        try {
            app(RdServiceInWalletRefundClient::class)->creditWalletRefund(
                deskRefundReference: 'REF-2026-000305',
                orderId: 'RD4328',
                amount: '497.00',
            );
            $this->fail('Expected wallet credit details validation failure.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'rdservice.in wallet refund API did not return wallet credit details.',
                $exception->errors()['refund'][0] ?? null,
            );
        }
    }

    public function test_preserves_distinct_wallet_reference_and_transaction_id_semantics(): void
    {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'message' => 'Wallet credit created',
                'data' => [
                    'wallet_transaction_id' => 91001,
                    'wallet_reference' => 'RD91001',
                    'source_system' => 'radium_desk',
                    'desk_refund_reference' => 'REF-2026-009920',
                    'credit' => '499.00',
                    'currency' => 'INR',
                    'balance' => '499.00',
                ],
            ], 201),
        ]);

        $result = app(RdServiceInWalletRefundClient::class)->creditWalletRefund(
            deskRefundReference: 'REF-2026-009920',
            orderId: 'RD3437801',
            amount: '499.00',
        );

        $this->assertSame('RD91001', $result['wallet_reference']);
        $this->assertSame(91001, $result['wallet_transaction_id']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function expectWalletCreditDetailsValidationError(array $data): void
    {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'message' => 'Wallet credit created',
                'data' => array_merge([
                    'source_system' => 'radium_desk',
                    'desk_refund_reference' => 'REF-2026-000305',
                ], $data),
            ], 201),
        ]);

        try {
            app(RdServiceInWalletRefundClient::class)->creditWalletRefund(
                deskRefundReference: 'REF-2026-000305',
                orderId: 'RD4328',
                amount: '497.00',
            );
            $this->fail('Expected wallet credit details validation failure.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'rdservice.in wallet refund API did not return wallet credit details.',
                $exception->errors()['refund'][0] ?? null,
            );
        }
    }
}
