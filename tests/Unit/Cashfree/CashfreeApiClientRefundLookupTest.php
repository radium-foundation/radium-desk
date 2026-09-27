<?php

namespace Tests\Unit\Cashfree;

use App\Services\Cashfree\CashfreeApiClient;
use App\Services\Cashfree\CashfreeConfigurationValidator;
use App\Services\Cashfree\Exceptions\CashfreeApiException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CashfreeApiClientRefundLookupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cashfree.verify_signature' => true,
            'cashfree.client_secret' => 'webhook-hmac-secret',
            'cashfree.api.app_id' => 'test-app-id',
            'cashfree.api.secret' => 'test-api-secret',
            'cashfree.api.base_url' => 'https://api.cashfree.test/pg',
            'cashfree.api.version' => '2026-01-01',
        ]);
    }

    public function test_pg_api_credentials_absent_fails_without_affecting_webhook_validation(): void
    {
        config([
            'cashfree.api.app_id' => null,
            'cashfree.api.secret' => null,
        ]);

        $client = app(CashfreeApiClient::class);
        $validator = app(CashfreeConfigurationValidator::class);

        $this->assertFalse($client->isPgApiConfigured());
        $this->assertFalse($client->isConfigured());
        $this->assertTrue($validator->isValid());

        $this->expectException(CashfreeApiException::class);
        $this->expectExceptionMessage('Cashfree PG API credentials are not configured');

        $client->getOrderRefunds('RN92');
    }

    public function test_get_order_refunds_uses_get_with_expected_path_headers_and_parses_list(): void
    {
        Http::fake([
            'https://api.cashfree.test/pg/orders/RN92/refunds' => Http::response([
                [
                    'refund_id' => 'REF_known',
                    'refund_amount' => 579,
                    'refund_status' => 'SUCCESS',
                ],
            ], 200),
        ]);

        $refunds = app(CashfreeApiClient::class)->getOrderRefunds('RN92');

        $this->assertCount(1, $refunds);
        $this->assertSame('REF_known', $refunds[0]['refund_id']);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && $request->url() === 'https://api.cashfree.test/pg/orders/RN92/refunds'
                && $request->hasHeader('x-client-id', 'test-app-id')
                && $request->hasHeader('x-client-secret', 'test-api-secret')
                && $request->hasHeader('x-api-version', '2026-01-01');
        });
    }

    public function test_get_order_refunds_parses_wrapped_refunds_key(): void
    {
        Http::fake([
            'https://api.cashfree.test/pg/orders/RN92/refunds' => Http::response([
                'refunds' => [
                    ['refund_id' => 'REF_wrapped', 'refund_amount' => 579],
                ],
            ], 200),
        ]);

        $refunds = app(CashfreeApiClient::class)->getOrderRefunds('RN92');

        $this->assertCount(1, $refunds);
        $this->assertSame('REF_wrapped', $refunds[0]['refund_id']);
    }

    public function test_get_refund_uses_get_with_expected_path_and_returns_entity(): void
    {
        Http::fake([
            'https://api.cashfree.test/pg/orders/RN92/refunds/REF_63f03b1b-ff4c-497e-b773-cd5536695d00' => Http::response([
                'refund_id' => 'REF_63f03b1b-ff4c-497e-b773-cd5536695d00',
                'order_id' => 'RN92',
                'cf_payment_id' => '6550886804',
                'refund_amount' => 579,
                'refund_status' => 'SUCCESS',
                'refund_arn' => 'ARN123',
                'created_at' => '2026-09-22T12:00:00+05:30',
                'processed_at' => '2026-09-22T12:05:00+05:30',
            ], 200),
        ]);

        $refund = app(CashfreeApiClient::class)->getRefund(
            'RN92',
            'REF_63f03b1b-ff4c-497e-b773-cd5536695d00',
        );

        $this->assertSame('REF_63f03b1b-ff4c-497e-b773-cd5536695d00', $refund['refund_id']);
        $this->assertSame(579, $refund['refund_amount']);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && $request->url() === 'https://api.cashfree.test/pg/orders/RN92/refunds/REF_63f03b1b-ff4c-497e-b773-cd5536695d00';
        });

        Http::assertSentCount(1);
    }

    public function test_malformed_refund_list_response_raises_exception(): void
    {
        Http::fake([
            'https://api.cashfree.test/pg/orders/RN92/refunds' => Http::response([
                'unexpected' => 'shape',
            ], 200),
        ]);

        $this->expectException(CashfreeApiException::class);
        $this->expectExceptionMessage('was not a refunds list');

        app(CashfreeApiClient::class)->getOrderRefunds('RN92');
    }

    public function test_failed_refund_detail_response_raises_exception(): void
    {
        Http::fake([
            'https://api.cashfree.test/pg/orders/RN92/refunds/REF_missing' => Http::response([
                'message' => 'not found',
            ], 404),
        ]);

        $this->expectException(CashfreeApiException::class);
        $this->expectExceptionMessage('Cashfree API GET /orders/RN92/refunds/REF_missing failed with HTTP 404');

        app(CashfreeApiClient::class)->getRefund('RN92', 'REF_missing');
    }

    public function test_existing_order_and_payment_get_behavior_unchanged(): void
    {
        Http::fake([
            'https://api.cashfree.test/pg/orders/RN92' => Http::response([
                'order_id' => 'RN92',
                'order_status' => 'PAID',
            ], 200),
            'https://api.cashfree.test/pg/orders/RN92/payments' => Http::response([
                ['cf_payment_id' => '6550886804', 'payment_status' => 'SUCCESS'],
            ], 200),
        ]);

        $client = app(CashfreeApiClient::class);

        $order = $client->getOrder('RN92');
        $payments = $client->getOrderPayments('RN92');

        $this->assertSame('RN92', $order['order_id']);
        $this->assertSame('6550886804', $payments[0]['cf_payment_id']);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET' && str_ends_with($request->url(), '/orders/RN92');
        });
        Http::assertSent(function ($request) {
            return $request->method() === 'GET' && str_ends_with($request->url(), '/orders/RN92/payments');
        });
    }
}
