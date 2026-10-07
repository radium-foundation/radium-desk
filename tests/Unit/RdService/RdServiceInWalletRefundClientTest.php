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

    public function test_accepts_known_good_central_wallet_reference_cw_73(): void
    {
        $result = $this->creditFromProductionEnvelope(
            reference: 'REF-67379',
            orderId: 'RD13612',
            amount: '499.00',
            walletReference: 'CW:73',
            walletTransactionId: 73,
            httpStatus: 200,
            message: 'Central Wallet credit already exists',
        );

        $this->assertSame('CW:73', $result['wallet_reference']);
        $this->assertSame(73, $result['wallet_transaction_id']);
        $this->assertOutboundCreditRequest('REF-67379', 'RD13612', '499.00');
    }

    public function test_accepts_existing_credit_reference_cw_86(): void
    {
        $result = $this->creditFromProductionEnvelope(
            reference: 'REF-67385',
            orderId: 'RD14441',
            amount: '497.00',
            walletReference: 'CW:86',
            walletTransactionId: 86,
            httpStatus: 201,
            message: 'Central Wallet credit created',
        );

        $this->assertSame('CW:86', $result['wallet_reference']);
        $this->assertSame(86, $result['wallet_transaction_id']);
        $this->assertOutboundCreditRequest('REF-67385', 'RD14441', '497.00');
    }

    public function test_accepts_existing_credit_reference_cw_87(): void
    {
        $result = $this->creditFromProductionEnvelope(
            reference: 'REF-67384',
            orderId: 'RD15020',
            amount: '499.00',
            walletReference: 'CW:87',
            walletTransactionId: 87,
            httpStatus: 200,
            message: 'Central Wallet credit already exists',
        );

        $this->assertSame('CW:87', $result['wallet_reference']);
        $this->assertSame(87, $result['wallet_transaction_id']);
        $this->assertOutboundCreditRequest('REF-67384', 'RD15020', '499.00');
    }

    public function test_rejects_central_wallet_reference_with_invalid_characters(): void
    {
        foreach (['CW:86!', 'CW: 86', 'CW:', 'CW:0', 'CW:086', 'cw:86', 'CW:86.0', 'A:B', 'RD 91001', '2563 1'] as $reference) {
            $this->expectWalletCreditDetailsValidationError([
                'wallet_reference' => $reference,
                'wallet_transaction_id' => 86,
                'credit' => '497.00',
                'currency' => 'INR',
            ]);
        }
    }

    public function test_central_wallet_reference_still_enforces_amount_currency_source_and_reference(): void
    {
        $this->expectWalletCreditDetailsValidationError([
            'wallet_reference' => 'CW:86',
            'wallet_transaction_id' => 86,
            'credit' => '100.00',
            'currency' => 'INR',
            'desk_refund_reference' => 'REF-67385',
        ], 'REF-67385', '497.00');

        $this->expectWalletCreditDetailsValidationError([
            'wallet_reference' => 'CW:86',
            'wallet_transaction_id' => 86,
            'credit' => '497.00',
            'currency' => 'USD',
            'desk_refund_reference' => 'REF-67385',
        ], 'REF-67385', '497.00');

        $this->expectWalletCreditDetailsValidationError([
            'wallet_reference' => 'CW:86',
            'wallet_transaction_id' => 86,
            'credit' => '497.00',
            'currency' => 'INR',
            'source_system' => 'other',
            'desk_refund_reference' => 'REF-67385',
        ], 'REF-67385', '497.00');

        $this->expectWalletCreditDetailsValidationError([
            'wallet_reference' => 'CW:86',
            'wallet_transaction_id' => 86,
            'credit' => '497.00',
            'currency' => 'INR',
            'desk_refund_reference' => 'REF-OTHER',
        ], 'REF-67385', '497.00');
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
    private function expectWalletCreditDetailsValidationError(
        array $data,
        string $deskRefundReference = 'REF-2026-000305',
        string $amount = '497.00',
    ): void {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'message' => 'Wallet credit created',
                'data' => array_merge([
                    'source_system' => 'radium_desk',
                    'desk_refund_reference' => $deskRefundReference,
                ], $data),
            ], 201),
        ]);

        try {
            app(RdServiceInWalletRefundClient::class)->creditWalletRefund(
                deskRefundReference: $deskRefundReference,
                orderId: 'RD4328',
                amount: $amount,
            );
            $this->fail('Expected wallet credit details validation failure.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'rdservice.in wallet refund API did not return wallet credit details.',
                $exception->errors()['refund'][0] ?? null,
            );
        }
    }

    /**
     * @return array{wallet_reference: string, wallet_transaction_id: int, balance: string}
     */
    private function creditFromProductionEnvelope(
        string $reference,
        string $orderId,
        string $amount,
        string $walletReference,
        int $walletTransactionId,
        int $httpStatus,
        string $message,
    ): array {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refunds' => Http::response([
                'message' => $message,
                'data' => [
                    'source_system' => 'radium_desk',
                    'desk_refund_reference' => $reference,
                    'order_id' => $orderId,
                    'userid' => 77,
                    'central_wallet_id' => '00000000-0000-4000-8000-000000000086',
                    'desk_ledger_entry_id' => $walletTransactionId,
                    'credit' => $amount,
                    'currency' => 'INR',
                    'wallet_reference' => $walletReference,
                    'wallet_transaction_id' => $walletTransactionId,
                    'balance' => $amount,
                    'destination' => 'central_wallet',
                ],
            ], $httpStatus),
        ]);

        return app(RdServiceInWalletRefundClient::class)->creditWalletRefund(
            deskRefundReference: $reference,
            orderId: $orderId,
            amount: $amount,
        );
    }

    private function assertOutboundCreditRequest(string $reference, string $orderId, string $amount): void
    {
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($reference, $orderId, $amount): bool {
            $body = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://rdservice.in.test/api/integrations/v1/wallet-refunds'
                && $body === [
                    'source_system' => 'radium_desk',
                    'desk_refund_reference' => $reference,
                    'order_id' => $orderId,
                    'amount' => $amount,
                    'currency' => 'INR',
                ];
        });
    }
}
