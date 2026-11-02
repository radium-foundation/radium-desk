<?php

namespace Tests\Unit\RadiumBox;

use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxWalletRefundReversalClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RadiumBoxWalletRefundReversalClientTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://radiumbox.test';

    private const REVERSAL_URL = self::BASE.RadiumBoxWalletRefundReversalClient::REVERSAL_PATH;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'radiumbox.wallet_refund_reversal_enabled' => true,
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => self::BASE,
            'order_lookup.spokes.radiumbox_com.token' => 'desk-box-wallet-token',
        ]);
    }

    public function test_legacy_reversal_posts_required_contract_fields_and_bearer_auth(): void
    {
        Http::fake([
            self::REVERSAL_URL => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_reversal_transaction_id' => 2588,
                    'wallet_reversal_reference' => '2588',
                    'desk_refund_reference' => 'REF-67445',
                    'debit' => '617.00',
                    'balance' => '0.00',
                ],
            ], 201),
        ]);

        $refund = $this->walletRefundFixture('RB1092', 'REF-67445', '617.00', '2588');

        $result = app(RadiumBoxWalletRefundReversalClient::class)->reverseWalletRefund(
            refund: $refund,
            orderId: 'RB1092',
            amount: '617.00',
            idempotencyKey: 'refund-revoke:67445',
            customerEmail: 'buyer@example.com',
        );

        $this->assertSame('2588', $result['wallet_reversal_reference']);
        $this->assertSame('2588', $result['wallet_reversal_transaction_id']);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === self::REVERSAL_URL
                && $request->hasHeader('Authorization', 'Bearer desk-box-wallet-token')
                && ($data['source_system'] ?? null) === RadiumBoxWalletRefundReversalClient::SOURCE_SYSTEM
                && ($data['desk_refund_reference'] ?? null) === 'REF-67445'
                && ($data['order_id'] ?? null) === 'RB1092'
                && ($data['amount'] ?? null) === '617.00'
                && ($data['currency'] ?? null) === 'INR'
                && ($data['idempotency_key'] ?? null) === 'refund-revoke:67445'
                && ($data['original_wallet_transaction_id'] ?? null) === '2588'
                && ($data['customer_email'] ?? null) === 'buyer@example.com'
                && ! array_key_exists('central_wallet_authoritative', $data);
        });
    }

    public function test_desk_authoritative_audit_retirement_posts_central_wallet_flag_without_spendable_debit(): void
    {
        Http::fake([
            self::REVERSAL_URL => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_reversal_transaction_id' => 99,
                    'wallet_reversal_reference' => '99',
                    'desk_refund_reference' => 'REF-MBOX-AUDIT',
                    'debit' => '0.00',
                    'balance' => '0.00',
                    'destination' => 'central_wallet',
                ],
            ], 201),
        ]);

        app(RadiumBoxWalletRefundReversalClient::class)->reverseDeskAuthoritativeAudit(
            deskRefundReference: 'REF-MBOX-AUDIT',
            orderId: 'RB1160',
            amount: '617.00',
            idempotencyKey: 'refund-revoke:1160',
            centralWalletLedgerCreditId: 96,
            customerEmail: 'buyer@example.com',
        );

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === self::REVERSAL_URL
                && ($data['source_system'] ?? null) === 'radium_desk'
                && ($data['central_wallet_authoritative'] ?? false) === true
                && ($data['original_wallet_transaction_id'] ?? null) === '96'
                && ! array_key_exists('debit', $data);
        });
    }

    public function test_not_configured_when_reversal_flag_off(): void
    {
        config(['radiumbox.wallet_refund_reversal_enabled' => false]);

        $refund = $this->walletRefundFixture('RB1', 'REF-X', '10.00', '1');

        $this->expectException(ValidationException::class);

        app(RadiumBoxWalletRefundReversalClient::class)->reverseWalletRefund(
            refund: $refund,
            orderId: 'RB1',
            amount: '10.00',
            idempotencyKey: 'refund-revoke:1',
        );
    }

    public function test_api_validation_failure_surfaces_message(): void
    {
        Http::fake([
            self::REVERSAL_URL => Http::response([
                'message' => 'source_system is invalid',
            ], 422),
        ]);

        $refund = $this->walletRefundFixture('RB2', 'REF-Y', '10.00', '2');

        try {
            app(RadiumBoxWalletRefundReversalClient::class)->reverseWalletRefund(
                refund: $refund,
                orderId: 'RB2',
                amount: '10.00',
                idempotencyKey: 'refund-revoke:2',
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertSame('source_system is invalid', collect($exception->errors())->flatten()->first());
        }
    }

    public function test_authentication_failure_surfaces_message(): void
    {
        Http::fake([
            self::REVERSAL_URL => Http::response(['message' => 'Unauthenticated.'], 401),
        ]);

        $refund = $this->walletRefundFixture('RB3', 'REF-Z', '10.00', '3');

        $this->expectException(ValidationException::class);

        app(RadiumBoxWalletRefundReversalClient::class)->reverseWalletRefund(
            refund: $refund,
            orderId: 'RB3',
            amount: '10.00',
            idempotencyKey: 'refund-revoke:3',
        );
    }

    public function test_connection_failure_is_retryable_validation_message(): void
    {
        Http::fake(function () {
            throw new ConnectionException('timeout');
        });

        $refund = $this->walletRefundFixture('RB4', 'REF-CONN', '10.00', '4');

        try {
            app(RadiumBoxWalletRefundReversalClient::class)->reverseWalletRefund(
                refund: $refund,
                orderId: 'RB4',
                amount: '10.00',
                idempotencyKey: 'refund-revoke:4',
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('temporarily unavailable', (string) collect($exception->errors())->flatten()->first());
        }
    }

    public function test_duplicate_idempotent_response_is_accepted(): void
    {
        Http::fake([
            self::REVERSAL_URL => Http::response([
                'status' => 200,
                'data' => [
                    'wallet_reversal_transaction_id' => 500,
                    'wallet_reversal_reference' => '500',
                    'desk_refund_reference' => 'REF-DUP',
                    'debit' => '25.00',
                    'balance' => '0.00',
                ],
            ], 200),
        ]);

        $refund = $this->walletRefundFixture('RB5', 'REF-DUP', '25.00', '500');

        $first = app(RadiumBoxWalletRefundReversalClient::class)->reverseWalletRefund(
            refund: $refund,
            orderId: 'RB5',
            amount: '25.00',
            idempotencyKey: 'refund-revoke:dup',
        );

        $second = app(RadiumBoxWalletRefundReversalClient::class)->reverseWalletRefund(
            refund: $refund,
            orderId: 'RB5',
            amount: '25.00',
            idempotencyKey: 'refund-revoke:dup',
        );

        $this->assertSame($first, $second);
        Http::assertSentCount(2);
    }

    private function walletRefundFixture(
        string $orderNumber,
        string $reference,
        string $amount,
        string $walletTransactionId,
    ): RefundRequest {
        $admin = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => $orderNumber,
            'serial_number' => 'SN-'.$orderNumber,
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => 'active',
            'payment_amount' => $amount,
            'customer_email' => 'customer@example.com',
            'created_by' => $admin->id,
        ]);

        return RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => $reference,
            'amount' => $amount,
            'refund_amount' => $amount,
            'reason' => 'Test',
            'status' => RefundStatus::Closed,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $admin->id,
            'execution_transaction_id' => $walletTransactionId,
            'communication_channels' => [],
        ]);
    }
}
