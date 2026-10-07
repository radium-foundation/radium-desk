<?php

namespace Tests\Unit\RadiumBox;

use App\Services\RadiumBox\RadiumBoxWalletRefundClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RadiumBoxWalletRefundClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'radiumbox.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'desk-box-wallet-token',
            'order_lookup.spokes.radiumbox_com.connect_timeout_seconds' => 3,
            'order_lookup.spokes.radiumbox_com.timeout_seconds' => 8,
        ]);
    }

    public function test_accepts_canonical_radiumbox_wallet_credit_response(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'message' => 'Wallet credit created',
                'data' => [
                    'wallet_transaction_id' => 273105,
                    'wallet_reference' => 'RD273105',
                    'desk_refund_reference' => 'REF-67330',
                    'userid' => 42,
                    'credit' => 617,
                    'balance' => 617,
                ],
            ], 201),
        ]);

        $result = app(RadiumBoxWalletRefundClient::class)->creditWalletRefund(
            deskRefundReference: 'REF-67330',
            orderId: 'RB317',
            amount: 617.00,
            customerEmail: 'customer@example.com',
        );

        $this->assertSame('RD273105', $result['wallet_reference']);
        $this->assertSame(273105, $result['wallet_transaction_id']);
        $this->assertSame(617.0, $result['balance']);
    }

    public function test_accepts_numeric_wallet_reference_from_json(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'message' => 'Wallet credit created',
                'data' => [
                    'wallet_transaction_id' => 273105,
                    'wallet_reference' => 273105,
                    'desk_refund_reference' => 'REF-67330',
                    'credit' => 617,
                    'balance' => 617,
                ],
            ], 201),
        ]);

        $result = app(RadiumBoxWalletRefundClient::class)->creditWalletRefund(
            deskRefundReference: 'REF-67330',
            orderId: 'RB317',
            amount: 617.00,
        );

        $this->assertSame('273105', $result['wallet_reference']);
        $this->assertSame(273105, $result['wallet_transaction_id']);
    }

    public function test_derives_wallet_reference_from_transaction_id_when_txnid_missing(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 200,
                'message' => 'Wallet credit already exists',
                'data' => [
                    'wallet_transaction_id' => 273105,
                    'desk_refund_reference' => 'REF-67330',
                    'credit' => 617,
                    'balance' => 617,
                ],
            ], 200),
        ]);

        $result = app(RadiumBoxWalletRefundClient::class)->creditWalletRefund(
            deskRefundReference: 'REF-67330',
            orderId: 'RB317',
            amount: 617.00,
        );

        $this->assertSame('RD273105', $result['wallet_reference']);
        $this->assertSame(273105, $result['wallet_transaction_id']);
    }

    public function test_accepts_txnid_alias_when_wallet_reference_missing(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'message' => 'Wallet credit created',
                'data' => [
                    'wallet_transaction_id' => 273105,
                    'txnid' => 'RD273105',
                    'desk_refund_reference' => 'REF-67330',
                    'credit' => 617,
                    'balance' => 617,
                ],
            ], 201),
        ]);

        $result = app(RadiumBoxWalletRefundClient::class)->creditWalletRefund(
            deskRefundReference: 'REF-67330',
            orderId: 'RB317',
            amount: 617.00,
        );

        $this->assertSame('RD273105', $result['wallet_reference']);
    }

    public function test_accepts_central_wallet_references_without_rewriting_them(): void
    {
        foreach ([
            ['CW:1', 1],
            ['CW:73', 73],
            ['CW:86', 86],
            ['CW:87', 87],
            ['CW:999999', 999999],
        ] as [$reference, $ledgerId]) {
            Http::swap(new Factory);
            Http::fake([
                'https://radiumbox.test/api/integrations/v1/wallet-refunds' => Http::response([
                    'status' => 201,
                    'message' => 'Central Wallet credit created',
                    'data' => [
                        'wallet_transaction_id' => $ledgerId,
                        'wallet_reference' => $reference,
                        'desk_refund_reference' => 'REF-67385',
                        'credit' => 497,
                        'balance' => 497,
                    ],
                ], 201),
            ]);

            $result = app(RadiumBoxWalletRefundClient::class)->creditWalletRefund(
                deskRefundReference: 'REF-67385',
                orderId: 'RB317',
                amount: 497.00,
            );

            $this->assertSame($reference, $result['wallet_reference']);
            $this->assertSame($ledgerId, $result['wallet_transaction_id']);
            $this->assertNotSame('RD'.$ledgerId, $result['wallet_reference']);
        }
    }

    public function test_rejects_invalid_central_wallet_references_instead_of_rewriting_them(): void
    {
        foreach (['CW:0', 'CW:00', 'CW:086', 'cw:86', 'CW:86!', 'CW:', 'CW:-1', 'A:B', '86abc'] as $reference) {
            $this->expectWalletCreditDetailsValidationError([
                'wallet_transaction_id' => 86,
                'wallet_reference' => $reference,
                'desk_refund_reference' => 'REF-67385',
                'credit' => 497,
                'balance' => 497,
            ]);
        }
    }

    public function test_rejects_central_wallet_reference_when_transaction_id_disagrees(): void
    {
        $this->expectWalletCreditDetailsValidationError([
            'wallet_transaction_id' => 99,
            'wallet_reference' => 'CW:86',
            'desk_refund_reference' => 'REF-67385',
            'credit' => 497,
            'balance' => 497,
        ]);
    }

    public function test_central_wallet_replay_preserves_the_same_cw_reference(): void
    {
        $data = [
            'wallet_transaction_id' => 86,
            'wallet_reference' => 'CW:86',
            'desk_refund_reference' => 'REF-67385',
            'credit' => 497,
            'balance' => 497,
        ];

        Http::fake([
            'https://radiumbox.test/api/integrations/v1/wallet-refunds' => Http::sequence()
                ->push(['status' => 201, 'message' => 'Central Wallet credit created', 'data' => $data], 201)
                ->push(['status' => 200, 'message' => 'Central Wallet credit already exists', 'data' => $data], 200),
        ]);

        $client = app(RadiumBoxWalletRefundClient::class);
        $first = $client->creditWalletRefund('REF-67385', 'RB317', 497.00);
        $second = $client->creditWalletRefund('REF-67385', 'RB317', 497.00);

        $this->assertSame('CW:86', $first['wallet_reference']);
        $this->assertSame(86, $first['wallet_transaction_id']);
        $this->assertSame($first, $second);
    }

    public function test_conflicting_amount_does_not_accept_a_central_wallet_reference(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/wallet-refunds' => Http::response([
                'message' => 'Wallet credit already exists for this refund with a different amount',
            ], 409),
        ]);

        try {
            app(RadiumBoxWalletRefundClient::class)->creditWalletRefund('REF-67385', 'RB317', 497.00);
            $this->fail('Expected the conflicting amount to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Wallet credit already exists for this refund with a different amount',
                $exception->errors()['refund'][0] ?? null,
            );
        }
    }

    public function test_idempotent_retry_returns_same_wallet_credit_details(): void
    {
        $payload = [
            'status' => 201,
            'message' => 'Wallet credit created',
            'data' => [
                'wallet_transaction_id' => 273105,
                'wallet_reference' => 'RD273105',
                'desk_refund_reference' => 'REF-67330',
                'credit' => 617,
                'balance' => 617,
            ],
        ];

        Http::fake([
            'https://radiumbox.test/api/integrations/v1/wallet-refunds' => Http::sequence()
                ->push($payload, 201)
                ->push([
                    'status' => 200,
                    'message' => 'Wallet credit already exists',
                    'data' => $payload['data'],
                ], 200),
        ]);

        $client = app(RadiumBoxWalletRefundClient::class);

        $first = $client->creditWalletRefund('REF-67330', 'RB317', 617.00);
        $second = $client->creditWalletRefund('REF-67330', 'RB317', 617.00);

        $this->assertSame($first, $second);
        Http::assertSentCount(2);
    }

    public function test_rejects_missing_wallet_credit_details(): void
    {
        $this->expectWalletCreditDetailsValidationError([
            'desk_refund_reference' => 'REF-67330',
            'credit' => 617,
            'balance' => 617,
        ]);
    }

    public function test_rejects_mismatched_desk_refund_reference(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'message' => 'Wallet credit created',
                'data' => [
                    'wallet_transaction_id' => 273105,
                    'wallet_reference' => 'RD273105',
                    'desk_refund_reference' => 'REF-OTHER',
                    'credit' => 617,
                    'balance' => 617,
                ],
            ], 201),
        ]);

        try {
            app(RadiumBoxWalletRefundClient::class)->creditWalletRefund('REF-67330', 'RB317', 617.00);
            $this->fail('Expected wallet credit details validation failure.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'RadiumBox wallet refund API did not return wallet credit details.',
                $exception->errors()['refund'][0] ?? null,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function expectWalletCreditDetailsValidationError(array $data): void
    {
        Http::swap(new Factory);
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'message' => 'Wallet credit created',
                'data' => $data,
            ], 201),
        ]);

        try {
            app(RadiumBoxWalletRefundClient::class)->creditWalletRefund('REF-67330', 'RB317', 617.00);
            $this->fail('Expected wallet credit details validation failure.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'RadiumBox wallet refund API did not return wallet credit details.',
                $exception->errors()['refund'][0] ?? null,
            );
        }
    }
}
